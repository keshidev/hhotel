<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoomAssignmentService
{
    public function lockRoomTypesForInventory(array $roomTypes): array
    {
        $normalizedRoomTypes = collect($roomTypes)
            ->map(fn ($roomType) => trim((string) $roomType))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $this->lockCandidateRoomsByType($normalizedRoomTypes);
    }

    public function assignRoomToBooking(
        Booking $booking,
        ?string $actorLabel = null,
        bool $allowHistoricalFallback = false
    ): array {
        $result = $this->assignPendingRooms(
            booking: $booking,
            actorLabel: $actorLabel,
            allowHistoricalFallback: $allowHistoricalFallback
        );

        if (! empty($result['unassigned'])) {
            Log::warning('Room auto-assignment left pending lines', [
                'booking_id' => $booking->id,
                'reference' => $booking->reference_number,
                'allow_historical_fallback' => $allowHistoricalFallback,
                'unassigned' => $result['unassigned'],
            ]);
        }

        return $result;
    }

    public function countAvailableRoomsByType(
        string $roomType,
        Carbon|string $checkIn,
        Carbon|string $checkOut,
        ?int $excludeBookingId = null,
        bool $websiteOnly = false
    ): int {
        $checkInAt = $checkIn instanceof Carbon ? $checkIn->copy() : Carbon::parse($checkIn);
        $checkOutAt = $checkOut instanceof Carbon ? $checkOut->copy() : Carbon::parse($checkOut);
        $now = now();
        $pendingExpiryCutoff = now()->subMinutes((int) config('bookings.pending_expiry_minutes', 30));

        $eligibleRoomsQuery = Room::query()->where('room_type', $roomType);
        if ($websiteOnly) {
            $eligibleRoomsQuery->where('show_on_website', true);
        }
        $this->applyRuntimeStatusFilter($eligibleRoomsQuery, $checkInAt);
        $eligibleRoomIds = $eligibleRoomsQuery->pluck('id')->all();
        if (empty($eligibleRoomIds)) {
            return 0;
        }

        $assignedBlockedCount = BookingRoom::query()
            ->whereIn('room_id', $eligibleRoomIds)
            ->whereNull('checked_out_at')
            ->whereHas('booking', function ($q) use ($checkInAt, $checkOutAt, $pendingExpiryCutoff, $now, $excludeBookingId) {
                $this->applyActiveBookingOverlapScope($q, $checkInAt, $checkOutAt, $pendingExpiryCutoff, $now);
                if ($excludeBookingId !== null) {
                    $q->where('id', '!=', $excludeBookingId);
                }
            })
            ->distinct('room_id')
            ->count('room_id');

        $sameBookingAssignedCount = $excludeBookingId === null
            ? 0
            : BookingRoom::query()
                ->where('booking_id', $excludeBookingId)
                ->whereIn('room_id', $eligibleRoomIds)
                ->whereNotNull('room_id')
                ->distinct('room_id')
                ->count('room_id');

        $rebookingHoldCount = DB::table('rebooking_room_holds')
            ->whereIn('room_id', $eligibleRoomIds)
            ->whereNull('released_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->when($excludeBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $excludeBookingId))
            ->where('check_in', '<', $checkOutAt->toDateString())
            ->where('check_out', '>', $checkInAt->toDateString())
            ->distinct('room_id')
            ->count('room_id');

        $pendingTypeHolds = BookingRoom::query()
            ->whereNull('room_id')
            ->where('requested_room_type', $roomType)
            ->whereHas('booking', function ($q) use ($checkInAt, $checkOutAt, $pendingExpiryCutoff, $now, $excludeBookingId) {
                $this->applyActiveBookingOverlapScope($q, $checkInAt, $checkOutAt, $pendingExpiryCutoff, $now);
                if ($excludeBookingId !== null) {
                    $q->where('id', '!=', $excludeBookingId);
                }
            })
            ->count();

        return max(0, count($eligibleRoomIds) - $assignedBlockedCount - $sameBookingAssignedCount - $pendingTypeHolds - $rebookingHoldCount);
    }

    public function getAvailableRoomsByType(
        string $roomType,
        Carbon|string $checkIn,
        Carbon|string $checkOut,
        ?int $excludeBookingId = null,
        bool $websiteOnly = false,
        array $excludeRoomIds = []
    ): EloquentCollection {
        $checkInAt = $checkIn instanceof Carbon ? $checkIn->copy() : Carbon::parse($checkIn);
        $checkOutAt = $checkOut instanceof Carbon ? $checkOut->copy() : Carbon::parse($checkOut);
        $now = now();
        $pendingExpiryCutoff = now()->subMinutes((int) config('bookings.pending_expiry_minutes', 30));

        $blockedRoomIds = BookingRoom::query()
            ->whereNotNull('room_id')
            ->whereNull('checked_out_at')
            ->whereHas('booking', function ($q) use ($checkInAt, $checkOutAt, $pendingExpiryCutoff, $now, $excludeBookingId) {
                $this->applyActiveBookingOverlapScope($q, $checkInAt, $checkOutAt, $pendingExpiryCutoff, $now);
                if ($excludeBookingId !== null) {
                    $q->where('id', '!=', $excludeBookingId);
                }
            })
            ->pluck('room_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($excludeBookingId !== null) {
            $blockedRoomIds = array_values(array_unique(array_merge(
                $blockedRoomIds,
                BookingRoom::query()
                    ->where('booking_id', $excludeBookingId)
                    ->whereNotNull('room_id')
                    ->pluck('room_id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            )));
        }


        $heldRoomIds = DB::table('rebooking_room_holds')
            ->whereNull('released_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->when($excludeBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $excludeBookingId))
            ->where('check_in', '<', $checkOutAt->toDateString())
            ->where('check_out', '>', $checkInAt->toDateString())
            ->pluck('room_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $blockedRoomIds = array_values(array_unique(array_merge($blockedRoomIds, $heldRoomIds)));

        $query = Room::query()
            ->where('room_type', $roomType)
            ->when($websiteOnly, fn ($q) => $q->where('show_on_website', true))
            ->when(! empty($excludeRoomIds), fn ($q) => $q->whereNotIn('id', $excludeRoomIds))
            ->when(! empty($blockedRoomIds), fn ($q) => $q->whereNotIn('id', $blockedRoomIds));

        $this->applyRuntimeStatusFilter($query, $checkInAt);

        return $query
            ->orderByRaw("CASE WHEN status = 'available' THEN 0 ELSE 1 END")
            ->orderBy('room_number')
            ->get(['id', 'room_number', 'room_type', 'floor', 'capacity', 'status', 'show_on_website']);
    }

    public function assignPendingRooms(
        Booking $booking,
        ?string $actorLabel = null,
        bool $allowHistoricalFallback = false
    ): array {
        return DB::transaction(
            fn () => $this->assignPendingRoomsLocked($booking, $actorLabel, $allowHistoricalFallback),
            3
        );
    }

    private function assignPendingRoomsLocked(
        Booking $booking,
        ?string $actorLabel,
        bool $allowHistoricalFallback
    ): array {
        $lockedBooking = Booking::query()
            ->with(['bookingRooms.room'])
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail();

        $pendingLines = $lockedBooking->bookingRooms
            ->filter(fn (BookingRoom $line) => $line->room_id === null && ! empty($line->requested_room_type))
            ->values();

        $assigned = [];
        $failed = [];
        $reservedRoomIds = $lockedBooking->bookingRooms
            ->pluck('room_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $lockedCandidatesByType = $this->lockCandidateRoomsByType(
            $pendingLines
                ->pluck('requested_room_type')
                ->filter()
                ->map(fn ($type) => (string) $type)
                ->unique()
                ->values()
                ->all()
        );

        foreach ($pendingLines as $line) {
            $type = (string) $line->requested_room_type;
            $candidateRooms = $lockedCandidatesByType[$type] ?? new EloquentCollection;
            $candidate = $candidateRooms->first(function (Room $room) use ($lockedBooking, $reservedRoomIds) {
                return ! in_array((int) $room->id, $reservedRoomIds, true)
                    && $this->isRuntimeEligible($room, Carbon::parse($lockedBooking->check_in))
                    && ! $this->roomHasOverlap($room, $lockedBooking);
            });

            if (! $candidate && $allowHistoricalFallback) {
                $candidate = $candidateRooms->first(function (Room $room) use ($lockedBooking, $reservedRoomIds) {
                    return ! in_array((int) $room->id, $reservedRoomIds, true)
                        && ! $this->roomHasOverlap($room, $lockedBooking, true);
                });
            }

            if (! $candidate) {
                $failed[] = [
                    'booking_room_id' => (int) $line->id,
                    'requested_room_type' => $type,
                    'reason' => 'no_available_room',
                ];

                continue;
            }

            $line->update(['room_id' => (int) $candidate->id]);
            $reservedRoomIds[] = (int) $candidate->id;

            $assigned[] = [
                'booking_room_id' => (int) $line->id,
                'requested_room_type' => $type,
                'room_id' => (int) $candidate->id,
                'room_number' => (string) $candidate->room_number,
            ];
        }

        $this->syncAssignmentStatus($lockedBooking);

        if (! empty($reservedRoomIds)) {
            app(RoomStateService::class)->recalculateMany($reservedRoomIds);
        }

        AuditHelper::log(
            actionActivity: empty($failed) && ! empty($assigned)
                ? 'Room Assigned After Payment Verification'
                : 'Room Assignment Pending Manual Review',
            modulePage: 'Booking Module',
            modelType: 'Booking',
            modelId: (int) $lockedBooking->id,
            recordAffected: 'Booking '.$lockedBooking->reference_number,
            oldValues: null,
            newValues: [
                'room_assignment_status' => $lockedBooking->room_assignment_status,
                'room_assigned_at' => optional($lockedBooking->room_assigned_at)->toDateTimeString(),
                'assigned' => $assigned,
                'unassigned' => $failed,
            ],
            action: 'updated',
            actorLabel: $actorLabel ?: 'System (Auto Assignment)'
        );

        return [
            'booking_id' => (int) $lockedBooking->id,
            'room_assignment_status' => (string) $lockedBooking->room_assignment_status,
            'room_assigned_at' => optional($lockedBooking->room_assigned_at)->toIso8601String(),
            'assigned' => $assigned,
            'unassigned' => $failed,
        ];
    }

    public function assignSpecificRoom(
        Booking $booking,
        int $roomId,
        ?int $bookingRoomId = null,
        ?string $actorLabel = null
    ): array {
        return DB::transaction(
            fn () => $this->assignSpecificRoomLocked($booking, $roomId, $bookingRoomId, $actorLabel),
            3
        );
    }

    private function assignSpecificRoomLocked(
        Booking $booking,
        int $roomId,
        ?int $bookingRoomId,
        ?string $actorLabel
    ): array {
        $lockedBooking = Booking::query()
            ->with(['bookingRooms.room'])
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail();

        $room = Room::query()->whereKey($roomId)->lockForUpdate()->first();
        if (! $room) {
            throw new \RuntimeException('ROOM_NOT_FOUND');
        }

        $pendingLines = $lockedBooking->bookingRooms
            ->filter(fn (BookingRoom $line) => $line->room_id === null)
            ->values();
        if ($pendingLines->isEmpty()) {
            throw new \RuntimeException('NO_PENDING_ASSIGNMENT');
        }

        $targetLine = null;
        if ($bookingRoomId !== null) {
            $targetLine = $pendingLines->first(fn (BookingRoom $line) => (int) $line->id === $bookingRoomId);
            if (! $targetLine) {
                throw new \RuntimeException('BOOKING_ROOM_NOT_PENDING');
            }
        } else {
            $matchingLines = $pendingLines
                ->filter(fn (BookingRoom $line) => (string) $line->requested_room_type === (string) $room->room_type)
                ->values();

            if ($matchingLines->isEmpty()) {
                throw new \RuntimeException('ROOM_TYPE_MISMATCH');
            }
            if ($matchingLines->count() > 1) {
                throw new \RuntimeException('BOOKING_ROOM_REQUIRED');
            }
            $targetLine = $matchingLines->first();
        }

        if ((string) $targetLine->requested_room_type !== (string) $room->room_type) {
            throw new \RuntimeException('ROOM_TYPE_MISMATCH');
        }

        if ($lockedBooking->bookingRooms
            ->contains(fn (BookingRoom $line) => $line->room_id !== null && (int) $line->room_id === (int) $room->id)) {
            throw new \RuntimeException('ROOM_ALREADY_ASSIGNED_TO_BOOKING');
        }

        if ($this->roomHasOverlap($room, $lockedBooking)) {
            throw new \RuntimeException('ROOM_OVERLAP');
        }

        $targetLine->update(['room_id' => (int) $room->id]);

        $this->syncAssignmentStatus($lockedBooking);
        app(RoomStateService::class)->recalculateMany([(int) $room->id]);

        AuditHelper::log(
            actionActivity: 'Room Assigned Manually',
            modulePage: 'Reservation Module',
            modelType: 'Booking',
            modelId: (int) $lockedBooking->id,
            recordAffected: 'Booking '.$lockedBooking->reference_number,
            oldValues: null,
            newValues: [
                'booking_room_id' => (int) $targetLine->id,
                'requested_room_type' => (string) $targetLine->requested_room_type,
                'room_id' => (int) $room->id,
                'room_number' => (string) $room->room_number,
                'room_assignment_status' => (string) $lockedBooking->room_assignment_status,
                'room_assigned_at' => optional($lockedBooking->room_assigned_at)->toDateTimeString(),
            ],
            action: 'updated',
            actorLabel: $actorLabel ?: (auth()->user()?->name ?? 'Receptionist')
        );

        return [
            'booking_id' => (int) $lockedBooking->id,
            'booking_room_id' => (int) $targetLine->id,
            'room_id' => (int) $room->id,
            'room_number' => (string) $room->room_number,
            'room_type' => (string) $room->room_type,
            'room_assignment_status' => (string) $lockedBooking->room_assignment_status,
            'room_assigned_at' => optional($lockedBooking->room_assigned_at)->toIso8601String(),
        ];
    }

    public function syncAssignmentStatus(Booking $booking): void
    {
        $hasPending = $booking->bookingRooms()->whereNull('room_id')->exists();

        $booking->refresh();
        $booking->update([
            'room_assignment_status' => $hasPending ? 'pending_assignment' : 'assigned',
            'room_assigned_at' => $hasPending ? null : ($booking->room_assigned_at ?? now()),
        ]);
        $booking->refresh();
    }

    private function applyRuntimeStatusFilter($query, Carbon $checkIn): void
    {
        if ($checkIn->isSameDay(Carbon::today())) {
            $query->where('status', 'available');

            return;
        }

        $query->whereNotIn('status', ['maintenance', 'cleaning']);
    }

    private function applyActiveBookingOverlapScope(
        $query,
        Carbon $checkIn,
        Carbon $checkOut,
        Carbon $pendingExpiryCutoff,
        Carbon $now
    ): void {
        $query->where(function ($statusQ) use ($pendingExpiryCutoff, $now) {
            $statusQ->whereIn('booking_status', ['confirmed', 'checked_in'])
                ->orWhere(function ($pendingQ) use ($pendingExpiryCutoff, $now) {
                    $pendingQ->where('booking_status', 'pending')
                        ->where(function ($expiryQ) use ($pendingExpiryCutoff, $now) {
                            $expiryQ->where(function ($hasExpiryQ) use ($now) {
                                $hasExpiryQ->whereNotNull('expires_at')
                                    ->where('expires_at', '>', $now);
                            })->orWhere(function ($legacyQ) use ($pendingExpiryCutoff) {
                                $legacyQ->whereNull('expires_at')
                                    ->where('created_at', '>=', $pendingExpiryCutoff);
                            });
                        });
                });
        })->where(function ($overlapQ) use ($checkIn, $checkOut) {
            $overlapQ->where('check_in', '<', $checkOut->toDateTimeString())
                ->whereRaw(
                    'COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?',
                    [$checkIn->toDateTimeString()]
                );
        });
    }

    private function lockCandidateRoomsByType(array $roomTypes): array
    {
        if (empty($roomTypes)) {
            return [];
        }

        $rooms = Room::query()
            ->whereIn('room_type', $roomTypes)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $roomsByType = [];
        foreach ($roomTypes as $roomType) {
            $roomsByType[$roomType] = new EloquentCollection(
                $rooms
                    ->where('room_type', $roomType)
                    ->sort(function (Room $left, Room $right) {
                        $leftStatusRank = $left->status === 'available' ? 0 : 1;
                        $rightStatusRank = $right->status === 'available' ? 0 : 1;

                        if ($leftStatusRank !== $rightStatusRank) {
                            return $leftStatusRank <=> $rightStatusRank;
                        }

                        $roomNumberComparison = strnatcasecmp((string) $left->room_number, (string) $right->room_number);

                        return $roomNumberComparison !== 0
                            ? $roomNumberComparison
                            : ((int) $left->id <=> (int) $right->id);
                    })
                    ->values()
                    ->all()
            );
        }

        return $roomsByType;
    }

    private function isRuntimeEligible(Room $room, Carbon $checkIn): bool
    {
        if ($checkIn->isSameDay(Carbon::today())) {
            return $room->status === 'available';
        }

        return ! in_array($room->status, ['maintenance', 'cleaning'], true);
    }

    private function roomHasOverlap(Room $room, Booking $booking, bool $historical = false): bool
    {
        $checkInAt = Carbon::parse($booking->check_in);
        $checkOutAt = Carbon::parse($booking->check_out);
        $pendingExpiryCutoff = now()->subMinutes((int) config('bookings.pending_expiry_minutes', 30));
        $now = now();

        return BookingRoom::query()
            ->where('room_id', $room->id)
            ->when(! $historical, fn ($query) => $query->whereNull('checked_out_at'))
            ->whereHas('booking', function ($q) use ($booking, $checkInAt, $checkOutAt, $pendingExpiryCutoff, $now, $historical) {
                if ($historical) {
                    $this->applyHistoricalBackfillOverlapScope($q, $checkInAt, $checkOutAt, $pendingExpiryCutoff, $now);
                } else {
                    $this->applyActiveBookingOverlapScope($q, $checkInAt, $checkOutAt, $pendingExpiryCutoff, $now);
                }
                $q->where('id', '!=', $booking->id);
            })
            ->lockForUpdate()
            ->first(['booking_rooms.id']) !== null;
    }

    private function applyHistoricalBackfillOverlapScope(
        $query,
        Carbon $checkIn,
        Carbon $checkOut,
        Carbon $pendingExpiryCutoff,
        Carbon $now
    ): void {
        $query->where(function ($statusQ) use ($pendingExpiryCutoff, $now) {
            $statusQ->whereIn('booking_status', ['confirmed', 'checked_in', 'checked_out'])
                ->orWhere(function ($pendingQ) use ($pendingExpiryCutoff, $now) {
                    $pendingQ->where('booking_status', 'pending')
                        ->where(function ($expiryQ) use ($pendingExpiryCutoff, $now) {
                            $expiryQ->where(function ($hasExpiryQ) use ($now) {
                                $hasExpiryQ->whereNotNull('expires_at')
                                    ->where('expires_at', '>', $now);
                            })->orWhere(function ($legacyQ) use ($pendingExpiryCutoff) {
                                $legacyQ->whereNull('expires_at')
                                    ->where('created_at', '>=', $pendingExpiryCutoff);
                            });
                        });
                });
        })->where(function ($overlapQ) use ($checkIn, $checkOut) {
            $overlapQ->where('check_in', '<', $checkOut->toDateTimeString())
                ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$checkIn->toDateTimeString()]);
        });
    }
}
