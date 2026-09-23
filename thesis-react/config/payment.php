<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Online Payment Provider
    |--------------------------------------------------------------------------
    |
    | Manual GCash is the only supported online payment flow.
    | Set the provider to disabled to stop new online bookings safely.
    |
    */

    'provider' => env('PAYMENT_PROVIDER'),

    'supported_providers' => [
        'disabled',
        'manual_gcash',
    ],

    'manual_gcash' => [
        'implemented' => true,
        'configuration_available' => true,
        'payment_window_minutes' => (int) env('MANUAL_GCASH_PAYMENT_WINDOW_MINUTES', 30),
        'review_target_minutes' => (int) env('MANUAL_GCASH_REVIEW_TARGET_MINUTES', 15),
        'review_hold_minutes' => (int) env('MANUAL_GCASH_REVIEW_HOLD_MINUTES', 120),
        'max_submission_attempts' => (int) env('MANUAL_GCASH_MAX_SUBMISSION_ATTEMPTS', 3),
        'max_proof_kilobytes' => (int) env('MANUAL_GCASH_MAX_PROOF_KILOBYTES', 5120),
        'prepare_attempts_per_minute' => (int) env('MANUAL_GCASH_PREPARE_ATTEMPTS_PER_MINUTE', 10),
        'prepare_ip_attempts_per_minute' => (int) env('MANUAL_GCASH_PREPARE_IP_ATTEMPTS_PER_MINUTE', 60),
        'proof_requests_per_minute' => (int) env('MANUAL_GCASH_PROOF_REQUESTS_PER_MINUTE', 10),
        'proof_ip_requests_per_minute' => (int) env('MANUAL_GCASH_PROOF_IP_REQUESTS_PER_MINUTE', 60),
        'resume_requests_per_minute' => (int) env('MANUAL_GCASH_RESUME_REQUESTS_PER_MINUTE', 10),
        'resume_link_requests_per_minute' => (int) env('MANUAL_GCASH_RESUME_LINK_REQUESTS_PER_MINUTE', 10),
        'resume_ip_requests_per_minute' => (int) env('MANUAL_GCASH_RESUME_IP_REQUESTS_PER_MINUTE', 60),
        'proof_retention_days' => (int) env('MANUAL_GCASH_PROOF_RETENTION_DAYS', 180),
        'monitoring' => [
            'reconciliation_max_age_minutes' => (int) env('MANUAL_GCASH_RECONCILIATION_MAX_AGE_MINUTES', 1440),
            'reconciliation_due_soon_minutes' => (int) env('MANUAL_GCASH_RECONCILIATION_DUE_SOON_MINUTES', 240),
            'reconciliation_exception_max_age_minutes' => (int) env('MANUAL_GCASH_RECONCILIATION_EXCEPTION_MAX_AGE_MINUTES', 1440),
            'refund_max_age_minutes' => (int) env('MANUAL_GCASH_REFUND_MAX_AGE_MINUTES', 1440),
            'queue_job_max_age_minutes' => (int) env('MANUAL_GCASH_QUEUE_JOB_MAX_AGE_MINUTES', 15),
        ],
        'launch' => [
            'merchant_verified' => (bool) env('MANUAL_GCASH_LAUNCH_MERCHANT_VERIFIED', false),
            'staff_trained' => (bool) env('MANUAL_GCASH_LAUNCH_STAFF_TRAINED', false),
            'backup_confirmed' => (bool) env('MANUAL_GCASH_LAUNCH_BACKUP_CONFIRMED', false),
            'rollback_reviewed' => (bool) env('MANUAL_GCASH_LAUNCH_ROLLBACK_REVIEWED', false),
            'production_approved' => (bool) env('MANUAL_GCASH_PRODUCTION_LAUNCH_APPROVED', false),
        ],
    ],
];
