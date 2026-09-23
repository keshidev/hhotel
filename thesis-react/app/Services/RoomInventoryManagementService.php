<?php

namespace App\Services;

use App\Exceptions\RoomInventoryConflictException;
use App\Models\BookingRoom;
use App\Models\Room;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RoomInventoryManagementService
{
    private const PROTECTED_BOOKING_STATUSES = ['pending', 'confirmed', 'checked_in'];

    private const STRUCTURAL_FIELDS = ['room_number', 'room_type', 'capacity', 'floor'];

    public function update(Room $room, array $attributes): Room
    {
        return DB::transaction(function () use ($room, $attributes) {
            $lockedRoom = Room::query()->lockForUpdate()->findOrFail($room->id);
            $this->assertUpdateIsSafe($lockedRoom, $attributes);
            $lockedRoom->update($attributes);

            return $lockedRoom->fresh();
        });
    }

    public function updateStatus(Room $room, string $status): Room
    {
        return DB::transaction(function () use ($room, $status) {
            $lockedRoom = Room::query()->lockForUpdate()->findOrFail($room->id);
            $this->assertStatusTransitionIsSafe($lockedRoom, $status);

            if ($lockedRoom->status !== $status) {
                $lockedRoom->update(['status' => $status]);
            }

            return $lockedRoom->fresh();
        });
    }

    public function delete(Room $room): Room
    {
        return $this->deleteMany([$room->id])->firstOrFail();
    }

    public function deleteMany(array $roomIds): Collection
    {
        $ids = collect($roomIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return DB::transaction(function () use ($ids) {
            $rooms = Room::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rooms->count() !== count($ids)) {
                throw new RoomInventoryConflictException('One or more selected rooms no longer exist. Refresh the page and try again.');
            }

            foreach ($rooms as $room) {
                if ($this->hasProtectedAssignment($room->id)) {
                    throw new RoomInventoryConflictException(
                        "Cannot delete room {$room->room_number}. Reassign or finish its active/upcoming booking first."
                    );
                }
            }

            foreach ($rooms as $room) {
                $room->delete();
            }

            return $rooms;
        });
    }

    private function assertUpdateIsSafe(Room $room, array $attributes): void
    {
        $status = (string) ($attributes['status'] ?? $room->status);
        $this->assertStatusTransitionIsSafe($room, $status);

        $structuralChange = collect(self::STRUCTURAL_FIELDS)->contains(function ($field) use ($room, $attributes) {
            return array_key_exists($field, $attributes)
                && (string) $attributes[$field] !== (string) $room->{$field};
        });

        if ($structuralChange && $this->hasProtectedAssignment($room->id)) {
            throw new RoomInventoryConflictException(
                'Cannot change the room number, type, capacity, or floor while this room has an active or upcoming booking. Reassign the booking first.'
            );
        }
    }

    private function assertStatusTransitionIsSafe(Room $room, string $status): void
    {
        if ($status === $room->status) {
            return;
        }

        if ($status === 'occupied') {
            throw new RoomInventoryConflictException(
                'Occupied status is controlled by guest check-in and cannot be set manually.'
            );
        }

        if ($status === 'available' && $this->hasCheckedInAssignment($room->id)) {
            throw new RoomInventoryConflictException(
                'Cannot mark this room available while a guest is checked in.'
            );
        }

        if (in_array($status, ['maintenance', 'cleaning'], true) && $this->hasProtectedAssignment($room->id)) {
            throw new RoomInventoryConflictException(
                'Cannot place this room in maintenance or cleaning while it has an active or upcoming booking. Reassign the booking first.'
            );
        }
    }

    private function hasProtectedAssignment(int $roomId): bool
    {
        return $this->assignmentQuery($roomId)
            ->whereHas('booking', fn ($query) => $query->whereIn('booking_status', self::PROTECTED_BOOKING_STATUSES))
            ->lockForUpdate()
            ->exists();
    }

    private function hasCheckedInAssignment(int $roomId): bool
    {
        return $this->assignmentQuery($roomId)
            ->whereHas('booking', fn ($query) => $query->where('booking_status', 'checked_in'))
            ->lockForUpdate()
            ->exists();
    }

    private function assignmentQuery(int $roomId)
    {
        return BookingRoom::query()
            ->where('room_id', $roomId)
            ->active();
    }
}
