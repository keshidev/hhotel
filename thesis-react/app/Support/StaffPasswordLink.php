<?php

namespace App\Support;

use App\Models\User;
use RuntimeException;

final class StaffPasswordLink
{
    public static function make(User $user, string $token, bool $setup = false): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $scheme = strtolower((string) parse_url($frontendUrl, PHP_URL_SCHEME));
        $host = parse_url($frontendUrl, PHP_URL_HOST);

        if (!$host || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('FRONTEND_URL must be a valid absolute URL.');
        }

        if (app()->environment('production') && $scheme !== 'https') {
            throw new RuntimeException('FRONTEND_URL must use HTTPS in production.');
        }

        $query = ['token' => $token, 'email' => $user->email];
        if ($setup) {
            $query['mode'] = 'setup';
        }

        return $frontendUrl . '/reset-password?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
