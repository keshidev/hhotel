<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\RoomAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingRoomAssignmentController extends Controller
{
    public function __construct(private RoomAssignmentService $roomAssignmentService)
    {
    }

    public function assignableRooms(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'booking_room_id' => 'nullable|integer',
        ]);

        $booking = $this->resolveBooking($id);
        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        }

        if (!$this->canManuallyAssignRoom($booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Room assignment is only allowed for confirmed or checked-in bookings.',
            ], 409);
        }

        $pendingLines = $booking->bookingRooms
            ->filter(fn ($line) => $line->room_id === null)
            ->values();

        if ($pendingLines->isEmpty()) {
            return response()->json([
                'success' => true,
                'booking_id' => (int) $booking->id,
                'room_assignment_status' => (string) $booking->room_assignment_status,
                'pending_lines' => [],
                'selected_booking_room_id' => null,
                'available_rooms' => [],
            ]);
        }

        $selectedLine = null;
        if ($request->filled('booking_room_id')) {
            $selectedLine = $pendingLines->first(
                fn ($line) => (int) $line->id === (int) $request->integer('booking_room_id')
            );
            if (!$selectedLine) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected booking room line is not pending assignment.',
                ], 422);
            }
        } else {
            $selectedLine = $pendingLines->first();
        }

        $availableRooms = $this->roomAssignmentService->getAvailableRoomsByType(
            roomType: (string) $selectedLine->requested_room_type,
            checkIn: $booking->check_in,
            checkOut: $booking->check_out,
            excludeBookingId: (int) $booking->id
        );

        return response()->json([
            'success' => true,
            'booking_id' => (int) $booking->id,
            'booking_reference' => (string) $booking->reference_number,
            'guest_name' => $booking->primaryGuest?->name,
            'room_assignment_status' => (string) ($booking->room_assignment_status ?? 'pending_assignment'),
            'pending_lines' => $pendingLines->map(fn ($line) => [
                'booking_room_id' => (int) $line->id,
                'requested_room_type' => (string) $line->requested_room_type,
                'price_per_night' => (float) $line->price_per_night,
                'nights' => (int) $line->nights,
                'subtotal' => (float) $line->subtotal,
            ])->values(),
            'selected_booking_room_id' => (int) $selectedLine->id,
            'available_rooms' => $availableRooms->map(fn ($room) => [
                'id' => (int) $room->id,
                'room_number' => (string) $room->room_number,
                'room_type' => (string) $room->room_type,
                'floor' => $room->floor,
                'capacity' => $room->capacity,
                'status' => (string) $room->status,
            ])->values(),
        ]);
    }

    public function assignRoom(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'booking_room_id' => 'nullable|integer|exists:booking_rooms,id',
        ]);

        $booking = $this->resolveBooking($id);
        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        }

        if (!$this->canManuallyAssignRoom($booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Room assignment is only allowed for confirmed or checked-in bookings.',
            ], 409);
        }

        try {
            $result = DB::transaction(function () use ($booking, $request) {
                return $this->roomAssignmentService->assignSpecificRoom(
                    booking: $booking,
                    roomId: (int) $request->integer('room_id'),
                    bookingRoomId: $request->filled('booking_room_id')
                        ? (int) $request->integer('booking_room_id')
                        : null,
                    actorLabel: auth()->user()?->name ?? 'Receptionist'
                );
            });
        } catch (\RuntimeException $e) {
            $statusCode = match ($e->getMessage()) {
                'NO_PENDING_ASSIGNMENT' => 409,
                'ROOM_TYPE_MISMATCH', 'BOOKING_ROOM_NOT_PENDING', 'BOOKING_ROOM_REQUIRED' => 422,
                'ROOM_OVERLAP', 'ROOM_ALREADY_ASSIGNED_TO_BOOKING' => 409,
                default => 422,
            };

            $message = match ($e->getMessage()) {
                'NO_PENDING_ASSIGNMENT' => 'This booking has no pending room assignment.',
                'ROOM_TYPE_MISMATCH' => 'Selected room type does not match the booked room type.',
                'BOOKING_ROOM_NOT_PENDING' => 'Selected booking room line is not pending assignment.',
                'BOOKING_ROOM_REQUIRED' => 'Multiple pending room lines found. Please choose a specific room line.',
                'ROOM_OVERLAP' => 'Selected room is not available for the booking dates.',
                'ROOM_ALREADY_ASSIGNED_TO_BOOKING' => 'Selected room is already assigned to another room line in this booking.',
                default => 'Unable to assign room.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $statusCode);
        }

        return response()->json([
            'success' => true,
            'message' => 'Room assigned successfully.',
            'data' => $result,
        ]);
    }

    private function resolveBooking(string $id): ?Booking
    {
        return Booking::query()
            ->with(['primaryGuest', 'bookingRooms.room'])
            ->where('id', $id)
            ->orWhere('reference_number', $id)
            ->first();
    }

    private function canManuallyAssignRoom(Booking $booking): bool
    {
        return in_array((string) $booking->booking_status, ['confirmed', 'checked_in'], true);
    }
}
