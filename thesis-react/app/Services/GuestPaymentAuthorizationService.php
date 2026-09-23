<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class GuestPaymentAuthorizationService
{
    public function __construct(private PaymentAccessSessionService $sessions) {}

    public function issueBootstrap(Booking $booking): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(max(5, (int) config('bookings.pending_expiry_minutes', 30)));

        if ($booking->expires_at && $expiresAt->greaterThan($booking->expires_at)) {
            $expiresAt = $booking->expires_at->copy();
        }

        $booking->forceFill([
            'payment_bootstrap_token_hash' => hash('sha256', $token),
            'payment_bootstrap_expires_at' => $expiresAt,
        ])->save();

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function bootstrapCookie(Request $request, int $bookingId, string $token, mixed $expiresAt): Cookie
    {
        return new Cookie(
            name: $this->bootstrapCookieName($bookingId),
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

    public function authorize(Request $request, Booking $booking): array
    {
        $accessToken = trim((string) $request->cookie($this->accessCookieName($booking->id), ''));
        $bootstrapToken = trim((string) $request->cookie($this->bootstrapCookieName($booking->id), ''));

        if ($accessToken !== '' && $this->sessions->validate($booking->id, $accessToken)) {
            return [
                'authorized' => true,
                'access_token' => $accessToken,
                'consume_bootstrap' => $bootstrapToken !== '' && $this->bootstrapMatches($booking, $bootstrapToken),
            ];
        }

        if ($bootstrapToken === '' || ! $this->bootstrapMatches($booking, $bootstrapToken)) {
            return [
                'authorized' => false,
                'status' => $bootstrapToken === '' ? 401 : 403,
                'message' => 'Payment authorization is required. Please return to your booking and continue to payment again.',
            ];
        }

        return [
            'authorized' => true,
            'access_token' => bin2hex(random_bytes(24)),
            'consume_bootstrap' => true,
        ];
    }

    public function validateStatus(Request $request, int $bookingId): bool
    {
        $token = trim((string) $request->cookie($this->accessCookieName($bookingId), ''));

        return $token !== '' && $this->sessions->validateForStatus($bookingId, $token);
    }

    public function activate(Booking $booking, string $accessToken, bool $consumeBootstrap): void
    {
        $this->sessions->store($booking->id, $accessToken);

        if ($consumeBootstrap) {
            $booking->update([
                'payment_bootstrap_token_hash' => null,
                'payment_bootstrap_expires_at' => null,
            ]);
        }
    }

    public function accessCookie(Request $request, int $bookingId, string $token): Cookie
    {
        return new Cookie(
            name: $this->accessCookieName($bookingId),
            value: $token,
            expire: now()->addMinutes(max(5, (int) config('bookings.payment_access_ttl_minutes', 120))),
            path: '/api/client',
            domain: null,
            secure: $request->isSecure() || app()->environment(['production', 'staging']),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_STRICT
        );
    }

    public function expiredBootstrapCookie(Request $request, int $bookingId): Cookie
    {
        return new Cookie(
            name: $this->bootstrapCookieName($bookingId),
            value: '',
            expire: 1,
            path: '/api/client',
            domain: null,
            secure: $request->isSecure() || app()->environment(['production', 'staging']),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX
        );
    }

    private function bootstrapMatches(Booking $booking, string $token): bool
    {
        if (! $booking->payment_bootstrap_token_hash) {
            return false;
        }

        if ($booking->payment_bootstrap_expires_at && now()->greaterThan($booking->payment_bootstrap_expires_at)) {
            return false;
        }

        return hash_equals((string) $booking->payment_bootstrap_token_hash, hash('sha256', $token));
    }

    private function bootstrapCookieName(int $bookingId): string
    {
        return (string) config('bookings.payment_bootstrap_cookie_prefix', 'hotel_payment_bootstrap_').$bookingId;
    }

    private function accessCookieName(int $bookingId): string
    {
        return (string) config('bookings.payment_access_cookie_prefix', 'hotel_payment_access_').$bookingId;
    }
}
