<?php

namespace App\Services;

use App\Models\BookingRoom;
use App\Models\Room;
use Illuminate\Support\Facades\DB;

class RoomStateService
{
    private static array $processing = [];

    /**
     * Recalculate a room's runtime status from active occupancy.
     * Only checked-in bookings force OCCUPIED.
     * Bookings affect availability by date via overlap checks, not via room.status.
     * Maintenance/cleaning remain explicit operational overrides unless occupied.
     */
    public function recalculate(int $roomId): void
    {
        // Guard against re-entrant calls (e.g. model observer firing after
        // recalculateMany was already called inside the same request).
        if (isset(self::$processing[$roomId])) {
            return;
        }
        self::$processing[$roomId] = true;

        try {
            $runner = function () use ($roomId) {
                $room = Room::whereKey($roomId)->lockForUpdate()->first();
                if (!$room) {
                    return;
                }

                $hasActiveOccupancy = BookingRoom::where('room_id', $roomId)
                    ->active()
                    ->whereHas('booking', function ($q) {
                        $q->where('booking_status', 'checked_in');
                    })
                    ->exists();

                if ($hasActiveOccupancy) {
                    if ($room->status !== 'occupied') {
                        $room->update(['status' => 'occupied']);
                    }
                    return;
                }

                // Maintenance / cleaning are explicit operational overrides — leave them alone.
                if (in_array($room->status, ['maintenance', 'cleaning'], true)) {
                    return;
                }

                if ($room->status !== 'available') {
                    $room->update(['status' => 'available']);
                }
            };

            if (DB::transactionLevel() > 0) {
                $runner();
            } else {
                DB::transaction($runner);
            }
        } finally {
            unset(self::$processing[$roomId]);
        }
    }

    public function recalculateMany(iterable $roomIds): void
    {
        foreach ($roomIds as $roomId) {
            $this->recalculate((int) $roomId);
        }
    }
}
