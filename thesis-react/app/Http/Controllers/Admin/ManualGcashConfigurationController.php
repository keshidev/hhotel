<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Services\ManualGcashConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManualGcashConfigurationController extends Controller
{
    public function __construct(private ManualGcashConfigurationService $manualGcash)
    {
    }

    public function show()
    {
        return response()->json(['data' => $this->manualGcash->status()]);
    }

    public function update(Request $request)
    {
        $existingConfiguration = $this->manualGcash->configuration();
        $hasConfiguration = $existingConfiguration !== null;
        $requiresQr = !$existingConfiguration
            || !$this->manualGcash->qrExists($existingConfiguration)
            || !$this->manualGcash->qrIntegrityIsValid($existingConfiguration);
        $validated = $request->validate([
            'merchant_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+() -]{7,25}$/'],
            'qr_image' => [
                Rule::requiredIf($requiresQr),
                'nullable',
                'file',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'max:5120',
                'dimensions:min_width=300,min_height=300,max_width=4000,max_height=4000',
            ],
            'ownership_confirmed' => [
                Rule::requiredIf($request->hasFile('qr_image')),
                'nullable',
                'accepted',
            ],
        ], [
            'qr_image.required' => 'Upload the official merchant GCash QR image.',
            'qr_image.max' => 'The merchant QR image must not exceed 5 MB.',
            'qr_image.dimensions' => 'The merchant QR image must be between 300 and 4000 pixels in width and height.',
            'account_number.regex' => 'The GCash account number contains unsupported characters.',
            'ownership_confirmed.required' => 'Confirm that you scanned the QR and verified the official hotel GCash account.',
            'ownership_confirmed.accepted' => 'Confirm that you scanned the QR and verified the official hotel GCash account.',
        ]);

        $configuration = $this->manualGcash->save(
            details: $validated,
            qrImage: $request->file('qr_image'),
            administrator: $request->user(),
        );

        AuditHelper::log(
            actionActivity: $hasConfiguration ? 'Manual GCash Configuration Updated' : 'Manual GCash Configuration Created',
            modulePage: 'Settings',
            modelType: 'Manual GCash Configuration',
            modelId: $configuration->id,
            recordAffected: $configuration->merchant_name,
            newValues: [
                'merchant_name' => $configuration->merchant_name,
                'account_name' => $configuration->account_name,
                'account_number' => $this->maskAccountNumber($configuration->account_number),
                'qr_replaced' => $request->hasFile('qr_image'),
            ],
            action: $hasConfiguration ? 'updated' : 'created',
            actorUser: $request->user(),
        );

        return response()->json([
            'message' => 'Manual GCash merchant configuration saved securely.',
            'data' => $this->manualGcash->status(),
        ]);
    }

    public function qr()
    {
        $configuration = $this->manualGcash->configuration();
        if (
            !$configuration
            || !$this->manualGcash->qrExists($configuration)
            || !$this->manualGcash->qrIntegrityIsValid($configuration)
        ) {
            return response()->json(['message' => 'Merchant QR image not found.'], 404);
        }

        $stream = $this->manualGcash->qrStream($configuration);
        if (!is_resource($stream)) {
            return response()->json(['message' => 'Merchant QR image is unavailable.'], 404);
        }

        $extension = match ($configuration->qr_mime_type) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $configuration->qr_mime_type,
            'Content-Length' => (string) $configuration->qr_size,
            'Content-Disposition' => "inline; filename=\"manual-gcash-merchant-qr.{$extension}\"",
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request)
    {
        $configuration = $this->manualGcash->configuration();
        if (!$configuration) {
            return response()->json(['message' => 'Manual GCash configuration is already empty.']);
        }

        $configurationId = $configuration->id;
        $merchantName = $configuration->merchant_name;
        $this->manualGcash->remove();

        AuditHelper::log(
            actionActivity: 'Manual GCash Configuration Removed',
            modulePage: 'Settings',
            modelType: 'Manual GCash Configuration',
            modelId: $configurationId,
            recordAffected: $merchantName,
            action: 'deleted',
            actorUser: $request->user(),
        );

        return response()->json([
            'message' => 'Manual GCash configuration and private QR image were removed.',
            'data' => $this->manualGcash->status(),
        ]);
    }

    private function maskAccountNumber(?string $accountNumber): ?string
    {
        if (!$accountNumber) {
            return null;
        }

        $visible = mb_substr($accountNumber, -4);

        return str_repeat('*', max(4, mb_strlen($accountNumber) - 4)) . $visible;
    }
}
