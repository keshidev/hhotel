<?php

$configuredOrigins = array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
));

$allowedOrigins = array_values(array_filter(array_unique([
    env('FRONTEND_URL'),
    ...$configuredOrigins,
])));

if (! in_array((string) env('APP_ENV', 'production'), ['production', 'staging'], true)) {
    $allowedOrigins = array_values(array_unique([
        ...$allowedOrigins,
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ]));
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
