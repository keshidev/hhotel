<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use LogicException;

/**
 * PromoCodeService
 *
 * Handles all promo code validation and discount calculation.
 * All validation runs server-side — never trust the frontend discount value.
 *
 * Usage:
 *   $service = app(PromoCodeService::class);
 *
 *   // Validate without applying (preview)
 *   $result = $service->validate($code, $params);
 *
 *   // Apply to a booking (inside a DB transaction)
 *   $service->apply($promoCode, $booking, $discountAmount);
 */
class PromoCodeService
{
    /**
     * Validate a promo code against the booking context.
     *
     * @param  string $code         The promo code string entered by the user.
     * @param  array  $params {
     *   string  guest_email      Email used for per-user limit tracking.
     *   string  check_in         Y-m-d
     *   string  check_out        Y-m-d
     *   float   subtotal         Room price × nights (before discount).
     *   string  booking_source   'online' | 'walk_in'
     * }
     *
     * @return array {
     *   bool    valid
     *   string  message
     *   float   discount_amount   (0 if invalid)
     *   float   final_total       (subtotal if invalid)
     *   PromoCode|null promo
     * }
     */
    public function validate(string $code, array $params): array
    {
        return $this->validateCode($code, $params, false);
    }

    public function validateForReservation(string $code, array $params): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Promo reservations must be validated inside a database transaction.');
        }

        return $this->validateCode($code, $params, true);
    }

    private function validateCode(string $code, array $params, bool $lockForUpdate): array
    {
        $fail = fn (string $msg) => [
            'valid'           => false,
            'message'         => $msg,
            'discount_amount' => 0.0,
            'final_total'     => (float) ($params['subtotal'] ?? 0),
            'promo'           => null,
        ];

        // ── 1. Code existence ─────────────────────────────────────────────────
        $promoQuery = PromoCode::where('code', strtoupper(trim($code)));
        if ($lockForUpdate) {
            $promoQuery->lockForUpdate();
        }
        $promo = $promoQuery->first();
        if (!$promo) {
            return $fail('Invalid promo code.');
        }

        // ── 2. Active status ──────────────────────────────────────────────────
        if (!$promo->is_active) {
            return $fail('This promo code is no longer active.');
        }

        // ── 3. Date validity (today must be within start_date–end_date) ───────
        $today = Carbon::today();
        if ($today->lt($promo->start_date) || $today->gt($promo->end_date)) {
            return $fail('This promo code is expired or not yet active.');
        }

        // ── 4. Eligible check-in date restriction ─────────────────────────────
        // Check-in must fall within any configured stay-date boundary.
        if ($promo->booking_start_date || $promo->booking_end_date) {
            $checkIn = Carbon::parse($params['check_in']);
            if (
                $promo->booking_start_date
                && $promo->booking_end_date
                && ($checkIn->lt($promo->booking_start_date) || $checkIn->gt($promo->booking_end_date))
            ) {
                return $fail(
                    'This promo code is only valid for stays between '
                    . $promo->booking_start_date->format('M d, Y')
                    . ' and '
                    . $promo->booking_end_date->format('M d, Y') . '.'
                );
            }

            if ($promo->booking_start_date && $checkIn->lt($promo->booking_start_date)) {
                return $fail(
                    'This promo code is only valid for check-ins on or after '
                    . $promo->booking_start_date->format('M d, Y') . '.'
                );
            }

            if ($promo->booking_end_date && $checkIn->gt($promo->booking_end_date)) {
                return $fail(
                    'This promo code is only valid for check-ins on or before '
                    . $promo->booking_end_date->format('M d, Y') . '.'
                );
            }
        }

        // ── 5. Min / max nights ───────────────────────────────────────────────
        $nights = Carbon::parse($params['check_in'])
            ->diffInDays(Carbon::parse($params['check_out']));
        $nights = max(1, $nights); // day tour counts as 1

        if ($nights < $promo->min_nights) {
            return $fail(
                "This promo code requires a minimum stay of {$promo->min_nights} night"
                . ($promo->min_nights > 1 ? 's' : '') . '.'
            );
        }

        if ($promo->max_nights !== null && $nights > $promo->max_nights) {
            return $fail(
                "This promo code is only valid for stays up to {$promo->max_nights} night"
                . ($promo->max_nights > 1 ? 's' : '') . '.'
            );
        }

        // ── 6. Channel restriction ────────────────────────────────────────────
        // If BOTH flags are set, the code is valid for all channels (no restriction).
        // Only restrict when exactly one flag is set.
        $source = $params['booking_source'] ?? 'online';

        if ($promo->online_only && !$promo->walk_in_only && $source !== 'online') {
            return $fail('This promo code is only valid for online bookings.');
        }

        if ($promo->walk_in_only && !$promo->online_only && $source !== 'walk_in') {
            return $fail('This promo code is only valid for walk-in bookings.');
        }

        // ── 7. Global usage limit ─────────────────────────────────────────────
        $activeUsageCount = $promo->usages()->active()->count();
        if ($promo->usage_limit !== null && $activeUsageCount >= $promo->usage_limit) {
            return $fail('This promo code has reached its maximum usage.');
        }

        // ── 8. Per-user usage limit ───────────────────────────────────────────
        $email = strtolower(trim($params['guest_email'] ?? ''));
        if ($email) {
            $userUsage = $promo->usageCountForEmail($email);
            if ($userUsage >= $promo->usage_per_user_limit) {
                return $fail('You have already used this promo code the maximum number of times.');
            }
        }

        // ── 9. Compute discount ───────────────────────────────────────────────
        $subtotal       = (float) ($params['subtotal'] ?? 0);
        $discountAmount = $promo->computeDiscount($subtotal);
        $finalTotal     = max(0, round($subtotal - $discountAmount, 2));

        return [
            'valid'           => true,
            'message'         => 'Promo code applied successfully.',
            'discount_amount' => $discountAmount,
            'final_total'     => $finalTotal,
            'promo'           => $promo,
            'savings_label'   => 'You saved ₱' . number_format($discountAmount, 2),
            'code_label'      => strtoupper($promo->code) . ' — '
                . ($promo->discount_type === 'percentage'
                    ? $promo->discount_value . '% OFF'
                    : '₱' . number_format($promo->discount_value, 2) . ' OFF'),
        ];
    }

    /**
     * Record that a promo code was used on a booking.
     * Must be called inside a DB transaction.
     *
     * @param  PromoCode $promo
     * @param  Booking   $booking
     * @param  float     $discountAmount
     * @param  string    $guestEmail
     */
    public function recordUsage(
        PromoCode $promo,
        Booking   $booking,
        float     $discountAmount,
        string    $guestEmail
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Promo usage must be recorded inside a database transaction.');
        }

        $isConsumed = in_array($booking->booking_status, ['confirmed', 'checked_in', 'checked_out'], true);
        $now = now();

        PromoCodeUsage::create([
            'promo_code_id'   => $promo->id,
            'booking_id'      => $booking->id,
            'guest_email'     => strtolower(trim($guestEmail)),
            'discount_amount' => $discountAmount,
            'status'          => $isConsumed
                ? PromoCodeUsage::STATUS_CONSUMED
                : PromoCodeUsage::STATUS_RESERVED,
            'reserved_at'     => $now,
            'consumed_at'     => $isConsumed ? $now : null,
            'used_at'         => $now,
        ]);

        if ($isConsumed) {
            $promo->increment('total_used');
        }
    }

    public function synchronizeForBooking(Booking $booking): void
    {
        if (DB::transactionLevel() < 1) {
            DB::transaction(function () use ($booking) {
                $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->first();
                if ($lockedBooking) {
                    $this->synchronizeForBooking($lockedBooking);
                }
            });

            return;
        }

        if (!$booking->promo_code_id) {
            return;
        }

        $usage = PromoCodeUsage::where('booking_id', $booking->id)
            ->lockForUpdate()
            ->first();

        if (!$usage) {
            return;
        }

        if (in_array($booking->booking_status, ['confirmed', 'checked_in', 'checked_out'], true)) {
            $this->consume($usage);

            return;
        }

        if (
            $usage->status === PromoCodeUsage::STATUS_RESERVED
            && in_array($booking->booking_status, ['cancelled', 'rejected', 'no_show'], true)
        ) {
            $usage->update([
                'status' => PromoCodeUsage::STATUS_RELEASED,
                'released_at' => now(),
                'release_reason' => 'booking_' . $booking->booking_status,
            ]);
        }
    }

    private function consume(PromoCodeUsage $usage): void
    {
        if ($usage->status === PromoCodeUsage::STATUS_CONSUMED) {
            return;
        }

        $promo = PromoCode::whereKey($usage->promo_code_id)->lockForUpdate()->first();
        if (!$promo) {
            return;
        }

        $usage->update([
            'status' => PromoCodeUsage::STATUS_CONSUMED,
            'consumed_at' => $usage->consumed_at ?? now(),
            'released_at' => null,
            'release_reason' => null,
        ]);

        $promo->increment('total_used');
    }
}
