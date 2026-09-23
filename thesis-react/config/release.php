<?php

return [
    'backup_evidence_path' => env(
        'RELEASE_BACKUP_EVIDENCE_PATH',
        storage_path('app/private/release/backup-verification.json')
    ),
    'backup_verification_max_age_hours' => max(
        1,
        (int) env('RELEASE_BACKUP_VERIFICATION_MAX_AGE_HOURS', 24)
    ),
    'backup_artifact_max_age_hours' => max(
        1,
        (int) env('RELEASE_BACKUP_ARTIFACT_MAX_AGE_HOURS', 48)
    ),
    'backup_minimum_bytes' => max(
        1,
        (int) env('RELEASE_BACKUP_MINIMUM_BYTES', 1024)
    ),
];
