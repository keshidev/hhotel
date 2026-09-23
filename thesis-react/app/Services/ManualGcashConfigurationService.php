<?php

namespace App\Services;

use App\Models\ManualGcashConfiguration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ManualGcashConfigurationService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function configuration(): ?ManualGcashConfiguration
    {
        return ManualGcashConfiguration::query()
            ->with('configuredBy:id,name')
            ->where('configuration_key', ManualGcashConfiguration::PRIMARY_KEY)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $configuration = $this->configuration();
        $issues = $this->issues($configuration);
        $qrExists = $configuration ? $this->qrExists($configuration) : false;
        $qrIntegrityValid = $configuration ? $this->qrIntegrityIsValid($configuration) : false;

        return [
            'configured' => $issues === [],
            'checkout_enabled' => $issues === [] && (bool) config('payment.manual_gcash.implemented', false),
            'issues' => $issues,
            'merchant_name' => $configuration?->merchant_name ?? '',
            'account_name' => $configuration?->account_name ?? '',
            'account_number' => $configuration?->account_number ?? '',
            'qr' => $configuration ? [
                'available' => $qrExists && $qrIntegrityValid,
                'integrity_valid' => $qrIntegrityValid,
                'original_name' => $configuration->qr_original_name,
                'mime_type' => $configuration->qr_mime_type,
                'size' => $configuration->qr_size,
                'width' => $configuration->qr_width,
                'height' => $configuration->qr_height,
                'uploaded_at' => $configuration->configured_at?->toIso8601String(),
                'uploaded_by' => $configuration->configuredBy?->name,
            ] : [
                'available' => false,
            ],
        ];
    }

    /**
     * @param  array{merchant_name: string, account_name: string, account_number?: string|null}  $details
     */
    public function save(array $details, ?UploadedFile $qrImage, User $administrator): ManualGcashConfiguration
    {
        $current = ManualGcashConfiguration::query()
            ->where('configuration_key', ManualGcashConfiguration::PRIMARY_KEY)
            ->first();

        if (! $current && ! $qrImage) {
            throw new RuntimeException('A merchant QR image is required for the initial configuration.');
        }

        $newQr = $qrImage ? $this->storeQrImage($qrImage) : null;
        $oldDisk = $current?->qr_disk;
        $oldPath = $current?->qr_path;

        try {
            $configuration = DB::transaction(function () use ($details, $newQr, $administrator) {
                $configuration = ManualGcashConfiguration::query()
                    ->where('configuration_key', ManualGcashConfiguration::PRIMARY_KEY)
                    ->lockForUpdate()
                    ->first() ?? new ManualGcashConfiguration([
                        'configuration_key' => ManualGcashConfiguration::PRIMARY_KEY,
                    ]);

                $configuration->fill([
                    'merchant_name' => trim($details['merchant_name']),
                    'account_name' => trim($details['account_name']),
                    'account_number' => $this->nullableTrim($details['account_number'] ?? null),
                    'configured_at' => now(),
                    'configured_by' => $administrator->id,
                ]);

                if ($newQr) {
                    $configuration->fill($newQr);
                }

                $configuration->save();

                return $configuration;
            });
        } catch (\Throwable $exception) {
            if ($newQr) {
                Storage::disk($newQr['qr_disk'])->delete($newQr['qr_path']);
            }

            throw $exception;
        }

        if ($newQr && $oldDisk && $oldPath && ($oldDisk !== $newQr['qr_disk'] || $oldPath !== $newQr['qr_path'])) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $configuration->fresh('configuredBy:id,name');
    }

    public function remove(): bool
    {
        $configuration = ManualGcashConfiguration::query()
            ->where('configuration_key', ManualGcashConfiguration::PRIMARY_KEY)
            ->first();

        if (! $configuration) {
            return false;
        }

        $disk = $configuration->qr_disk;
        $path = $configuration->qr_path;

        DB::transaction(fn () => $configuration->delete());
        Storage::disk($disk)->delete($path);

        return true;
    }

    public function qrExists(ManualGcashConfiguration $configuration): bool
    {
        return Storage::disk($configuration->qr_disk)->exists($configuration->qr_path);
    }

    public function qrIntegrityIsValid(ManualGcashConfiguration $configuration): bool
    {
        if (! $this->qrExists($configuration)) {
            return false;
        }

        $contents = Storage::disk($configuration->qr_disk)->get($configuration->qr_path);

        return hash_equals($configuration->qr_sha256, hash('sha256', $contents));
    }

    /**
     * @return resource|null
     */
    public function qrStream(ManualGcashConfiguration $configuration)
    {
        return Storage::disk($configuration->qr_disk)->readStream($configuration->qr_path);
    }

    /**
     * @return array<int, string>
     */
    public function issues(?ManualGcashConfiguration $configuration = null): array
    {
        $configuration ??= $this->configuration();
        if (! $configuration) {
            return ['manual_gcash_configuration_missing'];
        }

        $issues = [];
        if (trim($configuration->merchant_name) === '') {
            $issues[] = 'manual_gcash_merchant_name_missing';
        }
        if (trim($configuration->account_name) === '') {
            $issues[] = 'manual_gcash_account_name_missing';
        }
        if (! $this->qrExists($configuration)) {
            $issues[] = 'manual_gcash_qr_missing';
        } elseif (! $this->qrIntegrityIsValid($configuration)) {
            $issues[] = 'manual_gcash_qr_integrity_failed';
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    private function storeQrImage(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        $imageInfo = $realPath ? @getimagesize($realPath) : false;
        $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;

        if (! $realPath || ! $imageInfo || ! is_string($mimeType) || ! isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new RuntimeException('The uploaded merchant QR must be a valid PNG, JPEG, or WebP image.');
        }

        [$width, $height] = $imageInfo;
        if ($width < 300 || $height < 300 || $width > 4000 || $height > 4000) {
            throw new RuntimeException('The merchant QR image dimensions must be between 300 and 4000 pixels.');
        }

        $disk = 'manual_gcash';
        $path = 'merchant-qr-'.Str::uuid().'.'.self::ALLOWED_MIME_TYPES[$mimeType];
        $stored = Storage::disk($disk)->putFileAs('', $file, $path);

        if ($stored !== $path || ! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The merchant QR image could not be stored securely.');
        }

        $contents = Storage::disk($disk)->get($path);

        return [
            'qr_disk' => $disk,
            'qr_path' => $path,
            'qr_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'qr_mime_type' => $mimeType,
            'qr_size' => strlen($contents),
            'qr_width' => $width,
            'qr_height' => $height,
            'qr_sha256' => hash('sha256', $contents),
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
