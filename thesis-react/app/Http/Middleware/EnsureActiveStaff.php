<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $status = $user?->status;

        if ($user && $status === null && $user->exists) {
            $status = $user->newQuery()->whereKey($user->getKey())->value('status');
        }

        if ($user && $status !== 'active') {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your account is inactive. Please contact an administrator.',
            ], 403);
        }

        return $next($request);
    }
}
