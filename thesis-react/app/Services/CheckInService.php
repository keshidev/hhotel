<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CheckInService
{
    public function __construct(
        private CancellationApprovalService $cancellationApprovalService,
        private EarlyCheckInApprovalService $earlyCheckInApprovalService,
        private RoomStateService $roomStateService,
    ) {}

    /**
     * @return array{booking: Booking, early_check_in: bool, early_check_in_request_id: ?int, official_check_in: Carbon, room_ids: array<int>}
     */
    public function checkIn(
        int|string $bookingKey,
        User $actor,
    ): array {
        return DB::transaction(function () use ($bookingKey, $actor) {
            $booking = Booking::query()
                ->where(function ($query) use ($bookingKey) {
                    $query->where('reference_number', $bookingKey);
                    if (is_numeric($bookingKey)) {
                        $query->orWhere('id', (int) $bookingKey);
                    }
                })
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->booking_status !== 'confirmed') {
                throw new \RuntimeException('STATUS_INVALID:' . $booking->booking_status);
            }

            $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
                booking: $booking,
                actionCode: 'receptionist_checkin'
            );

            $payments = Payment::query()
                ->where('booking_id', $booking->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'amount', 'payment_type', 'payment_status']);

            $netPaid = Payment::netAmountFrom($payments);

            if ($netPaid <= 0.009) {
                throw new \RuntimeException('PAYMENT_REQUIRED');
            }

            $remainingBalance = round(max(0, (float) $booking->total_amount - $netPaid), 2);
            if ($remainingBalance > 0.009) {
                throw new \RuntimeException('BALANCE_REQUIRED:' . number_format($remainingBalance, 2, '.', ''));
            }

            $bookingRooms = BookingRoom::query()
                ->where('booking_id', $booking->id)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($bookingRooms->isEmpty() || $bookingRooms->contains(fn (BookingRoom $line) => $line->room_id === null)) {
                throw new \RuntimeException('ROOM_ASSIGNMENT_INCOMPLETE');
            }

            $roomIds = $bookingRooms
                ->pluck('room_id')
                ->map(fn ($roomId) => (int) $roomId)
                ->values();

            if ($roomIds->unique()->count() !== $roomIds->count()) {
                throw new \RuntimeException('ROOM_ASSIGNMENT_DUPLICATE');
            }

            $rooms = Room::query()
                ->whereIn('id', $roomIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rooms->count() !== $roomIds->count()) {
                throw new \RuntimeException('ROOM_ASSIGNMENT_INCOMPLETE');
            }

            $conflictingLine = BookingRoom::query()
                ->select('booking_rooms.booking_id')
                ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
                ->whereIn('booking_rooms.room_id', $roomIds->all())
                ->where('booking_rooms.booking_id', '!=', $booking->id)
                ->where(function ($query) {
                    $query->whereNull('booking_rooms.room_status')
                        ->orWhere('booking_rooms.room_status', 'active');
                })
                ->where('bookings.booking_status', 'checked_in')
                ->orderBy('booking_rooms.id')
                ->lockForUpdate()
                ->first();

            if ($conflictingLine) {
                $conflictingBooking = Booking::query()
                    ->with('primaryGuest')
                    ->find($conflictingLine->booking_id);

                throw new \RuntimeException(
                    'ROOM_CONFLICT:' . ($conflictingBooking?->reference_number ?? 'another booking')
                );
            }

            $blockedRoom = $rooms->first(fn (Room $room) => $room->status !== 'available');
            if ($blockedRoom) {
                throw new \RuntimeException(
                    'ROOM_NOT_READY:' . $blockedRoom->room_number . ':' . $blockedRoom->status
                );
            }

            $now = now();
            $checkInAt = Carbon::parse($booking->check_in);
            $checkOutAt = Carbon::parse($booking->check_out);

            if ($now->gte($checkOutAt)) {
                throw new \RuntimeException('CHECKIN_WINDOW_CLOSED:' . $checkOutAt->format('M d, Y g:i A'));
            }

            if ($now->isBefore($checkInAt->copy()->startOfDay())) {
                throw new \RuntimeException('CHECKIN_DATE_NOT_YET:' . $checkInAt->format('M d, Y'));
            }

            $officialCheckIn = $this->earlyCheckInApprovalService->officialCheckInAt($booking);
            $isEarlyCheckIn = $now->lt($officialCheckIn);
            $approval = $isEarlyCheckIn
                ? $this->earlyCheckInApprovalService->consumeApprovedForLockedBooking($booking, $actor, $officialCheckIn)
                : null;

            if (! $isEarlyCheckIn) {
                $this->earlyCheckInApprovalService->expireActiveForLockedBooking($booking, $officialCheckIn);
            }

            $booking->update([
                'booking_status' => 'checked_in',
                'reservation_status' => 'checked_in',
                'room_assignment_status' => 'assigned',
                'room_assigned_at' => $booking->room_assigned_at ?: $now,
                'checked_in_at' => $now,
                'checked_in_by' => $actor->id,
                'early_check_in_reason' => $approval?->reason,
            ]);

            $this->roomStateService->recalculateMany($roomIds->all());

            $booking->load(['primaryGuest', 'bookingRooms.room', 'creator', 'payments', 'checkedInBy']);

            return [
                'booking' => $booking,
                'early_check_in' => $isEarlyCheckIn,
                'early_check_in_request_id' => $approval?->id,
                'official_check_in' => $officialCheckIn,
                'room_ids' => $roomIds->all(),
            ];
        }, 3);
    }

}
