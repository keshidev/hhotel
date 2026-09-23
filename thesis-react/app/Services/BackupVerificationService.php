<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;
use Throwable;

class BackupVerificationService
{
    /**
     * @return array<string, mixed>
     */
    public function verifyAndRecord(
        string $databaseDump,
        string $privateFilesArchive,
        string $restoreTestReference
    ): array {
        $reference = trim($restoreTestReference);
        if ($reference === '' || mb_strlen($reference) > 255) {
            throw new InvalidArgumentException('A restore test reference between 1 and 255 characters is required.');
        }

        $database = $this->inspectArtifact($databaseDump, 'database dump');
        $privateFiles = $this->inspectArtifact($privateFilesArchive, 'private files archive');

        if ($database['resolved_path'] === $privateFiles['resolved_path']) {
            throw new InvalidArgumentException('Database and private-files backups must be separate artifacts.');
        }

        unset($database['resolved_path'], $privateFiles['resolved_path']);

        $verifiedAt = now();
        $evidence = [
            'version' => 1,
            'environment' => (string) config('app.env'),
            'verified_at' => $verifiedAt->toIso8601String(),
            'expires_at' => $verifiedAt
                ->copy()
                ->addHours((int) config('release.backup_verification_max_age_hours', 24))
                ->toIso8601String(),
            'restore_test_reference' => $reference,
            'artifacts' => [
                'database' => $database,
                'private_files' => $privateFiles,
            ],
        ];

        $this->writeEvidence($evidence);

        return $evidence;
    }

    /**
     * @return array{healthy: bool, reason: string, evidence: array<string, mixed>|null}
     */
    public function status(): array
    {
        $path = $this->evidencePath();
        if (! is_file($path) || ! is_readable($path)) {
            return ['healthy' => false, 'reason' => 'backup verification evidence is missing', 'evidence' => null];
        }

        try {
            $evidence = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['healthy' => false, 'reason' => 'backup verification evidence is invalid', 'evidence' => null];
        }

        if (! is_array($evidence)) {
            return ['healthy' => false, 'reason' => 'backup verification evidence is invalid', 'evidence' => null];
        }

        if (($evidence['environment'] ?? null) !== config('app.env')) {
            return ['healthy' => false, 'reason' => 'backup verification belongs to another environment', 'evidence' => $evidence];
        }

        $verifiedAtValue = trim((string) ($evidence['verified_at'] ?? ''));
        if ($verifiedAtValue === '') {
            return ['healthy' => false, 'reason' => 'backup verification timestamp is invalid', 'evidence' => $evidence];
        }

        try {
            $verifiedAt = Carbon::parse($verifiedAtValue);
        } catch (Throwable) {
            return ['healthy' => false, 'reason' => 'backup verification timestamp is invalid', 'evidence' => $evidence];
        }
        $maxAgeHours = (int) config('release.backup_verification_max_age_hours', 24);
        if ($verifiedAt->lt(now()->subHours($maxAgeHours))) {
            return ['healthy' => false, 'reason' => 'backup verification evidence is stale', 'evidence' => $evidence];
        }

        foreach (['database', 'private_files'] as $artifact) {
            if (empty($evidence['artifacts'][$artifact]['sha256']) || empty($evidence['artifacts'][$artifact]['bytes'])) {
                return ['healthy' => false, 'reason' => 'backup verification evidence is incomplete', 'evidence' => $evidence];
            }
        }

        if (trim((string) ($evidence['restore_test_reference'] ?? '')) === '') {
            return ['healthy' => false, 'reason' => 'restore test reference is missing', 'evidence' => $evidence];
        }

        return ['healthy' => true, 'reason' => 'fresh backup and restore evidence is present', 'evidence' => $evidence];
    }

    /**
     * @return array{name: string, bytes: int, sha256: string, modified_at: string, resolved_path: string}
     */
    private function inspectArtifact(string $path, string $label): array
    {
        $resolved = realpath(trim($path));
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new InvalidArgumentException("The {$label} does not exist or is not readable.");
        }

        $bytes = filesize($resolved);
        if ($bytes === false || $bytes < (int) config('release.backup_minimum_bytes', 1024)) {
            throw new InvalidArgumentException("The {$label} is smaller than the configured minimum size.");
        }

        $modifiedTimestamp = filemtime($resolved);
        if ($modifiedTimestamp === false) {
            throw new InvalidArgumentException("The {$label} modification time cannot be read.");
        }

        $modifiedAt = Carbon::createFromTimestamp($modifiedTimestamp);
        $maxAgeHours = (int) config('release.backup_artifact_max_age_hours', 48);
        if ($modifiedAt->lt(now()->subHours($maxAgeHours))) {
            throw new InvalidArgumentException("The {$label} is older than {$maxAgeHours} hours.");
        }

        $hash = hash_file('sha256', $resolved);
        if (! is_string($hash) || $hash === '') {
            throw new InvalidArgumentException("The {$label} checksum could not be generated.");
        }

        return [
            'name' => basename($resolved),
            'bytes' => $bytes,
            'sha256' => $hash,
            'modified_at' => $modifiedAt->toIso8601String(),
            'resolved_path' => $resolved,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function writeEvidence(array $evidence): void
    {
        $path = $this->evidencePath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException('Backup verification directory could not be created.');
        }

        $temporaryPath = $path.'.tmp';
        $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporaryPath, $json.PHP_EOL, LOCK_EX) === false || ! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new InvalidArgumentException('Backup verification evidence could not be saved.');
        }

        @chmod($path, 0640);
    }

    private function evidencePath(): string
    {
        return (string) config('release.backup_evidence_path');
    }
}
