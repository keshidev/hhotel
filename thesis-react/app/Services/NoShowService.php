<?php

namespace App\Services;

use App\Exceptions\NoShowTransitionException;
use App\Helpers\AuditHelper;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NoShowService
{
    public function __construct(
        private RoomStateService $roomStateService,
        private PaymentAccessSessionService $paymentAccessSessions,
    ) {}

    public function cutoffTime(): string
    {
        $configured = trim((string) SystemSetting::read(
            'no_show_cutoff_time',
            config('bookings.no_show.cutoff_time', '18:00')
        ));

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $configured)
            ? $configured
            : '18:00';
    }

    public function cutoffLabel(): string
    {
        return Carbon::createFromFormat('H:i', $this->cutoffTime())->format('g:i A');
    }

    public function mark(int|string $bookingKey, array $contact, ?User $actor): array
    {
        return DB::transaction(function () use ($bookingKey, $contact, $actor) {
            $booking = Booking::query()
                ->where(function ($query) use ($bookingKey) {
                    $query->where('reference_number', $bookingKey)
                        ->orWhere('id', $bookingKey);
                })
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->booking_status === 'no_show') {
                return [
                    'booking' => $booking->load(['primaryGuest', 'bookingRooms.room', 'payments']),
                    'already_processed' => true,
                ];
            }

            if ($booking->booking_status !== 'confirmed') {
                throw new NoShowTransitionException(
                    "Only confirmed bookings can be marked as no-show. Current status: {$booking->booking_status}.",
                    'BOOKING_NOT_CONFIRMED'
                );
            }

            $timezone = (string) config('app.timezone', 'Asia/Manila');
            $now = now($timezone);
            $cutoffTime = $this->cutoffTime();
            $cutoffAt = $this->cutoffFor($booking, $cutoffTime, $timezone);

            if ($cutoffAt->toDateString() > $now->toDateString()) {
                throw new NoShowTransitionException(
                    'No-Show cannot be marked before the check-in date.',
                    'NO_SHOW_BEFORE_CHECK_IN_DATE',
                    422,
                    ['eligible_at' => $cutoffAt->toIso8601String()]
                );
            }

            if ($cutoffAt->toDateString() === $now->toDateString() && $now->lt($cutoffAt)) {
                throw new NoShowTransitionException(
                    "No-Show can only be marked after {$cutoffAt->format('g:i A')} on the check-in date.",
                    'NO_SHOW_BEFORE_CUTOFF',
                    422,
                    ['eligible_at' => $cutoffAt->toIso8601String()]
                );
            }

            $contactedAt = Carbon::parse((string) $contact['contacted_at'], $timezone)->setTimezone($timezone);
            if ($contactedAt->gt($now->copy()->addMinutes(5))) {
                throw new NoShowTransitionException(
                    'The guest contact time cannot be in the future.',
                    'INVALID_CONTACT_TIME'
                );
            }

            $checkInDay = $booking->check_in
                ->copy()
                ->setTimezone($timezone)
                ->startOfDay();
            if ($contactedAt->lt($checkInDay)) {
                throw new NoShowTransitionException(
                    'The guest contact time cannot be before the check-in date.',
                    'INVALID_CONTACT_TIME'
                );
            }

            $bookingRooms = BookingRoom::query()
                ->where('booking_id', $booking->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $roomIds = $bookingRooms->pluck('room_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $rooms = Room::query()
                ->whereIn('id', $roomIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $payments = Payment::query()
                ->where('booking_id', $booking->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $booking->loadMissing('primaryGuest');
            $netPaid = Payment::netAmountFrom($payments);
            $financialDisposition = $netPaid > 0 ? 'manual_review_required' : 'no_payment';
            $roomLabel = $bookingRooms
                ->map(function (BookingRoom $line) use ($rooms) {
                    $room = $rooms->get($line->room_id);
                    if (! $room) {
                        return null;
                    }

                    return trim(($room->room_type ?? '') . ' ' . ($room->room_number ?? ''));
                })
                ->filter()
                ->implode(', ');
            $markedAt = now();
            $guestHasEmail = trim((string) $booking->primaryGuest?->email) !== '';

            $booking->update([
                'booking_status' => 'no_show',
                'reservation_status' => 'no_show',
                'no_show_marked_at' => $markedAt,
                'no_show_marked_by' => $actor?->id,
                'no_show_contacted' => true,
                'no_show_contact_method' => $contact['contact_method'],
                'no_show_contacted_at' => $contactedAt,
                'no_show_contact_outcome' => $contact['contact_outcome'],
                'no_show_contact_notes' => $contact['contact_notes'] ?? null,
                'no_show_cutoff_time' => $cutoffTime,
                'no_show_financial_disposition' => $financialDisposition,
                'no_show_financial_amount' => $netPaid,
                'no_show_email_status' => $guestHasEmail ? 'pending' : 'not_applicable',
                'no_show_email_attempts' => 0,
                'no_show_email_queued_at' => null,
                'no_show_email_sent_at' => null,
                'no_show_email_failed_at' => null,
                'no_show_email_last_error' => null,
            ]);

            $this->paymentAccessSessions->revoke((int) $booking->id, 'booking_no_show');
            $this->roomStateService->recalculateMany($roomIds);

            AuditHelper::log(
                actionActivity: 'Booking Marked as No-Show',
                modulePage: 'Check-In Module',
                modelType: 'Booking',
                modelId: (int) $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number
                    . ' - ' . ($booking->primaryGuest?->name ?? 'Guest')
                    . ($roomLabel !== '' ? " - Room {$roomLabel}" : ''),
                oldValues: ['booking_status' => 'confirmed'],
                newValues: [
                    'booking_status' => 'no_show',
                    'no_show_marked_at' => $markedAt->toDateTimeString(),
                    'no_show_marked_by' => $actor?->id,
                    'contact_method' => $contact['contact_method'],
                    'contacted_at' => $contactedAt->toDateTimeString(),
                    'contact_outcome' => $contact['contact_outcome'],
                    'contact_notes' => $contact['contact_notes'] ?? null,
                    'cutoff_time' => $cutoffTime,
                    'financial_disposition' => $financialDisposition,
                    'financial_amount' => $netPaid,
                ],
                action: 'no_show_marked',
                actorUser: $actor
            );

            return [
                'booking' => $booking->fresh()->load(['primaryGuest', 'bookingRooms.room', 'payments']),
                'already_processed' => false,
            ];
        }, 3);
    }

    private function cutoffFor(Booking $booking, string $cutoffTime, string $timezone): Carbon
    {
        if (! $booking->check_in) {
            throw new NoShowTransitionException(
                'Booking check-in date is missing.',
                'CHECK_IN_DATE_MISSING'
            );
        }

        [$hour, $minute] = array_map('intval', explode(':', $cutoffTime));

        return $booking->check_in
            ->copy()
            ->setTimezone($timezone)
            ->startOfDay()
            ->setTime($hour, $minute);
    }
}
