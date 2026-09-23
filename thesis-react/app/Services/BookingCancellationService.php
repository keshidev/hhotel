<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Cancellation;

class BookingCancellationService
{
    public function __construct(private RoomStateService $roomStateService)
    {
    }

    /**
     * Low-level booking cancellation mutator.
     * Caller must already hold booking row lock inside a DB transaction.
     */
    public function cancelLockedBooking(
        Booking $booking,
        string $cancelledReasonCode,
        string $reasonText,
        ?int $cancelledByUserId = null,
        string $refundStatus = 'none',
        float $refundAmount = 0.0,
        float $cancellationFee = 0.0
    ): void {
        if (in_array($booking->booking_status, ['checked_in', 'checked_out', 'no_show'], true)) {
            throw new \RuntimeException('BOOKING_NOT_CANCELLABLE:' . $booking->booking_status);
        }

        if ($booking->booking_status !== 'cancelled') {
            $booking->update([
                'booking_status' => 'cancelled',
                'reservation_status' => 'cancelled',
                'cancelled_reason' => $cancelledReasonCode,
                // Invalidate any pending guest tokens so they can't proceed.
                'payment_bootstrap_token_hash' => null,
                'payment_bootstrap_expires_at' => null,
            ]);
        }

        $paymentNote = trim((string) $reasonText) !== ''
            ? ('Booking cancelled: ' . trim((string) $reasonText))
            : 'Payment cancelled due to booking cancellation.';

        $booking->payments()
            ->where('payment_status', 'pending')
            ->update([
                'payment_status' => 'failed',
                'notes' => $paymentNote,
            ]);

        $cancellation = Cancellation::firstOrNew(['booking_id' => $booking->id]);

        if (!$cancellation->exists) {
            $cancellation->reason = $reasonText;
            $cancellation->cancelled_by = $cancelledByUserId;
            $cancellation->cancellation_fee = $cancellationFee;
            $cancellation->refund_amount = $refundAmount;
            $cancellation->refund_status = $refundStatus;
            $cancellation->cancelled_at = now();
            $cancellation->save();
        } else {
            // Do not blindly overwrite staff decisions; only enrich missing data.
            if (trim((string) $reasonText) !== '' && trim((string) $cancellation->reason) === '') {
                $cancellation->reason = $reasonText;
            }
            if ($cancelledByUserId !== null && $cancellation->cancelled_by === null) {
                $cancellation->cancelled_by = $cancelledByUserId;
            }
            if ($refundStatus !== 'none' && ($cancellation->refund_status ?? 'none') === 'none') {
                $cancellation->refund_status = $refundStatus;
            }
            if ($refundAmount > 0 && (float) $cancellation->refund_amount <= 0) {
                $cancellation->refund_amount = $refundAmount;
            }
            if ($cancellationFee > 0 && (float) $cancellation->cancellation_fee <= 0) {
                $cancellation->cancellation_fee = $cancellationFee;
            }
            if (!$cancellation->cancelled_at) {
                $cancellation->cancelled_at = now();
            }
            $cancellation->save();
        }

        $roomIds = $booking->bookingRooms()->pluck('room_id')->all();
        $this->roomStateService->recalculateMany($roomIds);
    }

    /**
     * Cancellation is non-refundable if request time is within 24 hours of check-in.
     */
    public function isNonRefundableNow(Booking $booking): bool
    {
        if (!$booking->check_in) {
            return false;
        }

        $checkIn = \Carbon\Carbon::parse($booking->check_in)->setTime(15, 0, 0);
        return now()->diffInHours($checkIn, false) < 24;
    }
}
