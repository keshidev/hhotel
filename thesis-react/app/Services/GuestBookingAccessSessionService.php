<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

class GuestBookingAccessSessionService
{
    public function issue(Request $request, Booking $booking): Cookie
    {
        $token = bin2hex(random_bytes(32));
        $now = now();
        $expiresAt = $now->copy()->addMinutes($this->ttlMinutes());

        DB::table('guest_booking_access_sessions')
            ->where(function ($query) use ($now) {
                $query->where('expires_at', '<=', $now)
                    ->orWhereNotNull('revoked_at');
            })
            ->delete();

        DB::table('guest_booking_access_sessions')->insert([
            'booking_id' => $booking->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'last_used_at' => $now,
            'revoked_at' => null,
            'revocation_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->trimActiveSessions($booking->id);

        return new Cookie(
            name: $this->cookieName($booking->id),
            value: $token,
            expire: $expiresAt,
            path: '/api/client',
            domain: null,
            secure: $request->isSecure() || app()->environment(['production', 'staging']),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_STRICT
        );
    }

    public function validate(Request $request, int $bookingId): bool
    {
        $token = trim((string) $request->cookie($this->cookieName($bookingId), ''));
        if (strlen($token) !== 64) {
            return false;
        }

        $session = DB::table('guest_booking_access_sessions')
            ->where('booking_id', $bookingId)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->whereNull('revoked_at')
            ->first(['id', 'last_used_at']);

        if (! $session) {
            return false;
        }

        $lastUsedAt = $session->last_used_at ? Carbon::parse($session->last_used_at) : null;
        if (! $lastUsedAt || $lastUsedAt->lt(now()->subMinutes(5))) {
            DB::table('guest_booking_access_sessions')
                ->where('id', $session->id)
                ->update(['last_used_at' => now(), 'updated_at' => now()]);
        }

        return true;
    }

    public function revoke(int $bookingId, string $reason): int
    {
        return DB::table('guest_booking_access_sessions')
            ->where('booking_id', $bookingId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => mb_substr(trim($reason), 0, 100),
                'updated_at' => now(),
            ]);
    }

    private function trimActiveSessions(int $bookingId): void
    {
        $keep = max(1, (int) config('bookings.guest_access_max_active_sessions', 5));
        $keepIds = DB::table('guest_booking_access_sessions')
            ->where('booking_id', $bookingId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->limit($keep)
            ->pluck('id');

        DB::table('guest_booking_access_sessions')
            ->where('booking_id', $bookingId)
            ->whereNull('revoked_at')
            ->whereNotIn('id', $keepIds)
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => 'active_session_limit',
                'updated_at' => now(),
            ]);
    }

    private function ttlMinutes(): int
    {
        return max(10, (int) config('bookings.guest_access_ttl_minutes', 60));
    }

    private function cookieName(int $bookingId): string
    {
        return (string) config('bookings.guest_access_cookie_prefix', 'hotel_guest_booking_').$bookingId;
    }
}
