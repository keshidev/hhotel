<?php

return [
    'duplicate_window_minutes' => max(1, (int) env('CONTACT_DUPLICATE_WINDOW_MINUTES', 10)),
    'retention_days' => max(30, (int) env('CONTACT_INQUIRY_RETENTION_DAYS', 365)),
    'rate_limit_per_minute' => max(1, (int) env('CONTACT_RATE_LIMIT_PER_MINUTE', 5)),
    'rate_limit_per_hour' => max(1, (int) env('CONTACT_RATE_LIMIT_PER_HOUR', 10)),
    'captcha' => [
        'enabled' => filter_var(env('CONTACT_CAPTCHA_ENABLED', env('BOOKING_CAPTCHA_ENABLED', false)), FILTER_VALIDATE_BOOL),
        'provider' => strtolower((string) env('CONTACT_CAPTCHA_PROVIDER', env('BOOKING_CAPTCHA_PROVIDER', 'recaptcha_v3'))),
        'secret' => env('CONTACT_CAPTCHA_SECRET', env('BOOKING_RECAPTCHA_SECRET')),
        'minimum_score' => (float) env('CONTACT_CAPTCHA_MINIMUM_SCORE', env('BOOKING_RECAPTCHA_MIN_SCORE', 0.5)),
        'expected_action' => env('CONTACT_CAPTCHA_EXPECTED_ACTION', 'contact_submit'),
        'expected_hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'CONTACT_CAPTCHA_EXPECTED_HOSTNAMES',
            env('BOOKING_RECAPTCHA_EXPECTED_HOSTNAMES', '')
        ))))),
    ],
];
