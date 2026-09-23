<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentAccessSessionService
{
    public const TERMINAL_BOOKING_STATUSES = [
        'confirmed',
        'checked_in',
        'checked_out',
        'cancelled',
        'no_show',
    ];

    public function store(int $bookingId, string $accessToken): void
    {
        $now = now();
        $expiresAt = $now->copy()->addMinutes($this->ttlMinutes());
        $tokenHash = hash('sha256', $accessToken);

        DB::table('payment_access_sessions')
            ->where('expires_at', '<=', $now)
            ->delete();

        DB::table('payment_access_sessions')->upsert(
            [[
                'booking_id' => $bookingId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'revocation_reason' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]],
            ['booking_id', 'token_hash'],
            ['expires_at', 'revoked_at', 'revocation_reason', 'updated_at']
        );
    }

    public function validate(int $bookingId, string $accessToken): bool
    {
        if (strlen($accessToken) < 20) {
            return false;
        }

        $tokenHash = hash('sha256', $accessToken);
        $isActive = DB::table('payment_access_sessions as sessions')
            ->join('bookings', 'bookings.id', '=', 'sessions.booking_id')
            ->where('sessions.booking_id', $bookingId)
            ->where('sessions.token_hash', $tokenHash)
            ->where('sessions.expires_at', '>', now())
            ->whereNull('sessions.revoked_at')
            ->where(function ($query) use ($bookingId) {
                $query->whereNotIn('bookings.booking_status', self::TERMINAL_BOOKING_STATUSES)
                    ->orWhere(function ($rebooking) use ($bookingId) {
                        $rebooking->where('bookings.booking_status', 'confirmed')
                            ->whereExists(function ($payments) use ($bookingId) {
                                $payments->selectRaw('1')
                                    ->from('payments')
                                    ->whereColumn('payments.booking_id', 'bookings.id')
                                    ->where('payments.booking_id', $bookingId)
                                    ->where('payments.purpose', 'rebooking_adjustment')
                                    ->where('payments.payment_status', 'pending');
                            });
                    });
            })
            ->exists();

        if ($isActive) {
            return true;
        }

        return $this->migrateLegacyToken($bookingId, $accessToken, $tokenHash);
    }

    public function validateForStatus(int $bookingId, string $accessToken): bool
    {
        if (strlen($accessToken) < 20) {
            return false;
        }

        $tokenHash = hash('sha256', $accessToken);
        $session = DB::table('payment_access_sessions as sessions')
            ->join('bookings', 'bookings.id', '=', 'sessions.booking_id')
            ->where('sessions.booking_id', $bookingId)
            ->where('sessions.token_hash', $tokenHash)
            ->where('sessions.expires_at', '>', now())
            ->first([
                'sessions.revoked_at',
                'bookings.booking_status',
            ]);

        if ($session) {
            $isTerminal = self::isTerminalBookingStatus((string) $session->booking_status);

            if ($isTerminal && $session->revoked_at === null) {
                $this->revoke($bookingId, 'booking_' . $session->booking_status);
            }

            return $session->revoked_at === null || $isTerminal;
        }

        return $this->migrateLegacyToken($bookingId, $accessToken, $tokenHash);
    }

    public function revoke(int $bookingId, string $reason): int
    {
        $revoked = DB::table('payment_access_sessions')
            ->where('booking_id', $bookingId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => mb_substr(trim($reason), 0, 100),
                'updated_at' => now(),
            ]);

        $this->forgetLegacyToken($bookingId);

        return $revoked;
    }

    public static function isTerminalBookingStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_BOOKING_STATUSES, true);
    }

    private function migrateLegacyToken(int $bookingId, string $accessToken, string $tokenHash): bool
    {
        $bookingStatus = DB::table('bookings')->where('id', $bookingId)->value('booking_status');
        if (self::isTerminalBookingStatus((string) $bookingStatus)
            && ! ($bookingStatus === 'confirmed' && $this->hasOpenRebookingPayment($bookingId))) {
            $this->forgetLegacyToken($bookingId);

            return false;
        }

        $key = "client_payment_access:{$bookingId}";
        $expectedHash = null;

        try {
            $expectedHash = Cache::store('file')->get($key);
        } catch (\Throwable $e) {
            Log::warning('File cache read failed while migrating payment access session', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);
        }

        if (empty($expectedHash)) {
            $expectedHash = Cache::get($key);
        }

        if (empty($expectedHash) || !hash_equals((string) $expectedHash, $tokenHash)) {
            return false;
        }

        $this->store($bookingId, $accessToken);

        try {
            Cache::store('file')->forget($key);
        } catch (\Throwable $e) {
            Log::warning('File cache cleanup failed after migrating payment access session', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);
        }
        Cache::forget($key);

        return true;
    }

    private function forgetLegacyToken(int $bookingId): void
    {
        $key = "client_payment_access:{$bookingId}";

        try {
            Cache::store('file')->forget($key);
        } catch (\Throwable $e) {
            Log::warning('File cache cleanup failed while revoking payment access session', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);
        }

        Cache::forget($key);
    }

    private function hasOpenRebookingPayment(int $bookingId): bool
    {
        return DB::table('payments')
            ->where('booking_id', $bookingId)
            ->where('purpose', 'rebooking_adjustment')
            ->where('payment_status', 'pending')
            ->exists();
    }

    private function ttlMinutes(): int
    {
        return max(5, (int) config('bookings.payment_access_ttl_minutes', 120));
    }
}
