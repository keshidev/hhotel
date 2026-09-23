<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnlineBookingIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) SystemSetting::read('maintenance_mode', false)) {
            return response()
                ->json([
                    'success' => false,
                    'error_code' => 'BOOKING_MAINTENANCE',
                    'message' => 'Online booking is temporarily unavailable while we perform maintenance. Please try again later or contact the hotel.',
                ], 503)
                ->header('Cache-Control', 'no-store')
                ->header('Retry-After', '300');
        }

        return $next($request);
    }
}
