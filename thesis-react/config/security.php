<?php

return [
    'headers_enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),
    'hsts_max_age' => max(0, (int) env('SECURITY_HSTS_MAX_AGE', 31536000)),
    'content_security_policy' => (string) env(
        'SECURITY_CONTENT_POLICY',
        "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/ https://maps.google.com https://www.google.com; worker-src 'self' blob:; upgrade-insecure-requests"
    ),
];
