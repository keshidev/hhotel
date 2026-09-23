<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',    
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Trust forwarded client IP headers only when explicitly configured.
        // Example:
        // TRUSTED_PROXIES=*
        // TRUSTED_PROXIES=127.0.0.1,10.0.0.1
        $trustedProxiesEnv = env('TRUSTED_PROXIES');

        if (is_string($trustedProxiesEnv) && trim($trustedProxiesEnv) !== '') {
            $allowWildcardInProd = (bool) env('TRUSTED_PROXIES_ALLOW_WILDCARD', false);
            $isWildcard = trim($trustedProxiesEnv) === '*';
            $trustedProxies = $isWildcard
                ? ((string) env('APP_ENV', 'production') === 'production' && !$allowWildcardInProd ? [] : '*')
                : array_values(array_filter(array_map('trim', explode(',', $trustedProxiesEnv))));

            if (!empty($trustedProxies)) {
                $middleware->trustProxies(at: $trustedProxies);
            }
        }

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'staff.active' => \App\Http\Middleware\EnsureActiveStaff::class,
            'online-booking.available' => \App\Http\Middleware\EnsureOnlineBookingIsAvailable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            $message = strtolower($e->getMessage());
            $code = strtoupper((string) $e->getCode());

            $isDatabaseUnavailable =
                $e instanceof QueryException
                || $e instanceof PDOException
                || str_contains($message, 'sqlstate[hy000] [2002]')
                || str_contains($message, 'target machine actively refused')
                || str_contains($message, 'server has gone away');

            if (!$isDatabaseUnavailable) {
                return null;
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Database temporarily unavailable — please try again.',
                    'error_code' => $code !== '' ? $code : 'DB_UNAVAILABLE',
                ], 503);
            }

            return response('Database temporarily unavailable — please try again.', 503);
        });
    })->create();
