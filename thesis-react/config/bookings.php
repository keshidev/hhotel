<?php

return [
    // Pending reservations auto-expire after this many minutes.
    'pending_expiry_minutes' => (int) env('BOOKING_PENDING_EXPIRY_MINUTES', 15),

    // Receptionists may backdate a walk-in only enough to cover form-entry delay.
    'walk_in_check_in_grace_minutes' => max(0, (int) env('BOOKING_WALK_IN_CHECK_IN_GRACE_MINUTES', 15)),

    // Maximum total checked-in stay length after receptionist extensions.
    'stay_extension_max_days' => max(1, (int) env('BOOKING_STAY_EXTENSION_MAX_DAYS', 30)),

    // Hard anti-spam cap for creating guest bookings.
    'max_per_ip_per_day' => (int) env('BOOKING_MAX_PER_IP_PER_DAY', 3),

    // Short window burst protection for shared NATs or bot spikes.
    'max_per_ip_per_minute' => (int) env('BOOKING_MAX_PER_IP_PER_MINUTE', 5),

    // Idempotency window for booking creation retries.
    'idempotency_ttl_minutes' => (int) env('BOOKING_IDEMPOTENCY_TTL_MINUTES', 30),

    // Host-only HttpOnly cookie used to hand verified bookings to payment.
    'payment_bootstrap_cookie_prefix' => env('BOOKING_PAYMENT_BOOTSTRAP_COOKIE_PREFIX', 'hotel_payment_bootstrap_'),
    'payment_access_cookie_prefix' => env('BOOKING_PAYMENT_ACCESS_COOKIE_PREFIX', 'hotel_payment_access_'),
    'payment_access_ttl_minutes' => (int) env('BOOKING_PAYMENT_ACCESS_TTL_MINUTES', 120),

    // Short-lived customer self-service authorization created after a successful
    // reference + email lookup. The raw token is stored only in an HttpOnly cookie.
    'guest_access_cookie_prefix' => env('BOOKING_GUEST_ACCESS_COOKIE_PREFIX', 'hotel_guest_booking_'),
    'guest_access_ttl_minutes' => (int) env('BOOKING_GUEST_ACCESS_TTL_MINUTES', 60),
    'guest_access_max_active_sessions' => (int) env('BOOKING_GUEST_ACCESS_MAX_ACTIVE_SESSIONS', 5),

    'no_show' => [
        'cutoff_time' => env('BOOKING_NO_SHOW_CUTOFF_TIME', '18:00'),
    ],

    // CAPTCHA gate for public booking creation.
    'captcha' => [
        'enabled'            => (bool) env('BOOKING_CAPTCHA_ENABLED', false),
        'provider'           => env('BOOKING_CAPTCHA_PROVIDER', 'recaptcha'),
        'secret'             => env('BOOKING_RECAPTCHA_SECRET', ''),
        'min_score'          => (float) env('BOOKING_RECAPTCHA_MIN_SCORE', 0.5),
        'expected_action'    => env('BOOKING_RECAPTCHA_EXPECTED_ACTION', 'booking_submit'),
        'expected_hostnames' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BOOKING_RECAPTCHA_EXPECTED_HOSTNAMES', ''))
        ))),
    ],

    // Throttle middleware overrides for client endpoints (blank to disable).
    'throttle' => [
        'rooms_available'  => env('BOOKING_THROTTLE_ROOMS_AVAILABLE', 'throttle:30,1'),
        'booking_status'   => env('BOOKING_THROTTLE_BOOKING_STATUS', 'throttle:10,1'),
    ],

    'feedback_link_ttl_minutes' => (int) env('BOOKING_FEEDBACK_LINK_TTL_MINUTES', 10080),
];
