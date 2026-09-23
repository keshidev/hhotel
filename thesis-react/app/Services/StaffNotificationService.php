<?php

namespace App\Services;

use App\Helpers\NotificationHelper;
use App\Mail\NewBookingAdminNotification;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StaffNotificationService
{
    public function emailNotificationsEnabled(): bool
    {
        return (bool) SystemSetting::read('email_notifications', true);
    }

    public function bookingNotificationsEnabled(): bool
    {
        return (bool) SystemSetting::read('booking_notifications', true);
    }

    public function notifyNewBooking(Booking $booking): array
    {
        if (!$this->bookingNotificationsEnabled()) {
            return ['emails_queued' => 0, 'in_app_created' => 0];
        }

        // Claim this notification only after the booking and its payment are
        // confirmed. The atomic update also prevents webhook retries or a
        // manual review retry from sending the same alert twice.
        $claimed = Booking::query()
            ->whereKey($booking->id)
            ->where('booking_status', 'confirmed')
            ->whereNull('staff_booking_notified_at')
            ->whereHas('payments', function ($paymentQuery) {
                $paymentQuery
                    ->where('payment_status', 'completed')
                    ->where('payment_type', '!=', Payment::TYPE_REFUND);
            })
            ->update(['staff_booking_notified_at' => now()]);

        if ($claimed !== 1) {
            return ['emails_queued' => 0, 'in_app_created' => 0];
        }

        $booking->refresh();
        $booking->loadMissing(['bookingRooms.room', 'primaryGuest', 'payments']);

        $staff = User::query()
            ->whereIn('role', ['admin', 'receptionist'])
            ->where('status', 'active')
            ->get();
        $emailEnabled = $this->emailNotificationsEnabled();
        $guestName = $booking->primaryGuest?->name ?? 'Guest';
        $guestEmail = $booking->primaryGuest?->email;
        $roomTypeLabels = $booking->bookingRooms
            ->map(fn ($bookingRoom) => $bookingRoom->room?->room_type ?? $bookingRoom->requested_room_type)
            ->filter()
            ->map(fn ($type) => $this->formatRoomType((string) $type))
            ->unique()
            ->values()
            ->join(', ');
        $downpaymentAmount = (float) $booking->payments
            ->where('payment_type', 'downpayment')
            ->sortByDesc('id')
            ->first()?->amount;
        $downpaymentPercentage = (float) ($booking->downpayment_percentage ?? 0);
        $checkIn = optional($booking->check_in)->format('M d, Y');
        $checkOut = optional($booking->check_out)->format('M d, Y');
        $emailsQueued = 0;
        $inAppCreated = 0;

        foreach ($staff as $staffMember) {
            if ($emailEnabled && filter_var($staffMember->email, FILTER_VALIDATE_EMAIL)) {
                try {
                    Mail::to($staffMember->email)->queue(new NewBookingAdminNotification($booking));
                    $emailsQueued++;
                } catch (\Throwable $exception) {
                    Log::warning('New booking staff email could not be queued', [
                        'booking_id' => $booking->id,
                        'user_id' => $staffMember->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            try {
                NotificationHelper::send(
                    userId: $staffMember->id,
                    type: 'booking_created',
                    title: 'New Confirmed Booking',
                    message: "New booking ({$booking->reference_number}) by {$guestName} for room type(s) {$roomTypeLabels}. "
                        . "Check-in: {$checkIn}, Check-out: {$checkOut}. "
                        . 'Downpayment: PHP ' . number_format($downpaymentAmount, 2)
                        . " ({$downpaymentPercentage}%). GCash payment verified; booking confirmed.",
                    data: [
                        'booking_id' => $booking->id,
                        'reference_number' => $booking->reference_number,
                        'guest_name' => $guestName,
                        'guest_email' => $guestEmail,
                        'room_types' => $roomTypeLabels,
                        'check_in' => optional($booking->check_in)->format('Y-m-d'),
                        'check_out' => optional($booking->check_out)->format('Y-m-d'),
                        'nights' => $booking->nights,
                        'total_amount' => (float) $booking->total_amount,
                        'downpayment_amount' => $downpaymentAmount,
                        'remaining_balance' => round(max(0, (float) $booking->total_amount - $downpaymentAmount), 2),
                    ]
                );
                $inAppCreated++;
            } catch (\Throwable $exception) {
                Log::warning('New booking in-app notification failed', [
                    'booking_id' => $booking->id,
                    'user_id' => $staffMember->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['emails_queued' => $emailsQueued, 'in_app_created' => $inAppCreated];
    }

    private function formatRoomType(string $roomType): string
    {
        return ucwords(str_replace(['_', '-'], ' ', trim($roomType)));
    }
}
