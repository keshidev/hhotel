<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers_enabled', true)) {
            return $response;
        }

        $headers = [
            'Content-Security-Policy' => trim((string) config('security.content_security_policy')),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        foreach ($headers as $name => $value) {
            if ($value !== '' && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $hstsMaxAge = (int) config('security.hsts_max_age', 0);
        if ($request->isSecure() && config('app.env') === 'production' && $hstsMaxAge > 0) {
            $response->headers->set(
                'Strict-Transport-Security',
                "max-age={$hstsMaxAge}; includeSubDomains"
            );
        }

        return $response;
    }
}
