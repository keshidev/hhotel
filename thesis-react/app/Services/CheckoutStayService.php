<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CheckoutStayService
{
    private const BALANCE_EPSILON = 0.009;

    public function __construct(
        private RoomStateService $roomStateService,
        private CancellationApprovalService $cancellationApprovalService,
    ) {
    }

    public function checkoutBooking(Booking $booking, int $actorId): Booking
    {
        $this->assertCheckedIn($booking);
        $this->assertLifecycleUnlocked($booking, 'receptionist_checkout');
        $this->assertBalanceSettled($booking);

        $activeLines = $this->activeLinesForUpdate($booking);
        if ($activeLines->isEmpty()) {
            throw new \RuntimeException('NO_ACTIVE_ROOMS');
        }

        $roomIds = $this->lockPhysicalRooms($activeLines);
        $checkedOutAt = now();

        BookingRoom::query()
            ->whereIn('id', $activeLines->pluck('id')->all())
            ->update([
                'room_status' => 'checked_out',
                'checked_out_at' => $checkedOutAt,
            ]);

        Room::query()
            ->whereIn('id', $roomIds)
            ->where('status', '!=', 'maintenance')
            ->update(['status' => 'cleaning']);

        $booking->update([
            'booking_status' => 'checked_out',
            'reservation_status' => 'completed',
            'checked_out_at' => $checkedOutAt,
            'checked_out_by' => $actorId,
        ]);

        $this->roomStateService->recalculateMany($roomIds);

        return $booking->refresh();
    }

    public function checkoutRoom(
        Booking $booking,
        int $roomId,
        ?int $bookingRoomId,
        int $actorId
    ): array {
        $this->assertCheckedIn($booking);
        $this->assertLifecycleUnlocked($booking, 'receptionist_room_checkout');
        $this->assertBalanceSettled($booking);

        $lineQuery = BookingRoom::query()->where('booking_id', $booking->id);
        if ($bookingRoomId !== null) {
            $lineQuery->whereKey($bookingRoomId);
        } else {
            $lineQuery->where('room_id', $roomId);
        }

        $line = $lineQuery->lockForUpdate()->first();
        if (! $line) {
            throw new \RuntimeException('ROOM_NOT_IN_BOOKING');
        }
        if ((int) ($line->room_id ?? 0) !== $roomId) {
            throw new \RuntimeException('ROOM_LINE_MISMATCH');
        }
        if (($line->room_status ?? 'active') !== 'active') {
            throw new \RuntimeException('ROOM_ALREADY_CHECKED_OUT');
        }

        $room = Room::query()->whereKey($roomId)->lockForUpdate()->first();
        if (! $room) {
            throw new \RuntimeException('ROOM_NOT_FOUND');
        }

        $activeCount = BookingRoom::query()
            ->where('booking_id', $booking->id)
            ->active()
            ->count();
        $isFinalRoom = $activeCount <= 1;

        $checkedOutAt = now();
        $line->update([
            'room_status' => 'checked_out',
            'checked_out_at' => $checkedOutAt,
        ]);

        if ($room->status !== 'maintenance') {
            $room->update(['status' => 'cleaning']);
        }

        if ($isFinalRoom) {
            $booking->update([
                'booking_status' => 'checked_out',
                'reservation_status' => 'completed',
                'checked_out_at' => $checkedOutAt,
                'checked_out_by' => $actorId,
            ]);
        } else {
            $this->syncBookingCheckOutFromActiveLines($booking);
        }

        $this->roomStateService->recalculate($roomId);

        return [$booking->refresh(), $line->refresh()];
    }

    public function assertExtensionAllowed(Booking $booking): void
    {
        $this->assertCheckedIn($booking);
        $this->assertLifecycleUnlocked($booking, 'receptionist_stay_extension');
    }

    public function effectiveCheckoutAt(BookingRoom $line, Booking $booking): Carbon
    {
        if ($line->extended_checkout !== null) {
            return Carbon::parse((string) $line->extended_checkout);
        }

        $hasLineExtension = BookingRoom::query()
            ->where('booking_id', $booking->id)
            ->whereNotNull('extended_checkout')
            ->exists();

        if (! $hasLineExtension) {
            return Carbon::parse((string) $booking->check_out);
        }

        $bookingCheckOut = Carbon::parse((string) $booking->check_out);

        return Carbon::parse((string) $booking->check_in)
            ->startOfDay()
            ->addDays(max(1, (int) ($line->nights ?? 1)))
            ->setTime($bookingCheckOut->hour, $bookingCheckOut->minute, $bookingCheckOut->second);
    }

    public function syncBookingCheckOutFromActiveLines(Booking $booking): void
    {
        $activeLines = BookingRoom::query()
            ->where('booking_id', $booking->id)
            ->active()
            ->get();

        if ($activeLines->isEmpty()) {
            return;
        }

        $maxCheckout = $activeLines
            ->map(fn (BookingRoom $line) => $this->effectiveCheckoutAt($line, $booking))
            ->sortBy(fn (Carbon $date) => $date->timestamp)
            ->last();

        if ($maxCheckout && ! Carbon::parse((string) $booking->check_out)->equalTo($maxCheckout)) {
            $booking->update(['check_out' => $maxCheckout->toDateTimeString()]);
        }
    }

    private function assertCheckedIn(Booking $booking): void
    {
        if ($booking->booking_status !== 'checked_in') {
            throw new \RuntimeException('STATUS_INVALID:' . $booking->booking_status);
        }
    }

    private function assertLifecycleUnlocked(Booking $booking, string $actionCode): void
    {
        $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
            booking: $booking,
            actionCode: $actionCode
        );
    }

    private function assertBalanceSettled(Booking $booking): void
    {
        $remainingBalance = (float) $booking->remaining_balance;
        if ($remainingBalance > self::BALANCE_EPSILON) {
            throw new \RuntimeException('BALANCE_DUE:' . $remainingBalance);
        }
    }

    private function activeLinesForUpdate(Booking $booking): Collection
    {
        return BookingRoom::query()
            ->where('booking_id', $booking->id)
            ->active()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lockPhysicalRooms(Collection $lines): array
    {
        $roomIds = $lines
            ->pluck('room_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($roomIds !== []) {
            Room::query()->whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get();
        }

        return $roomIds;
    }
}
