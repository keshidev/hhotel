<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomAssignmentService;
use App\Services\RoomTransferRequestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoomAllocationConflictSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('booking_rooms', function (Blueprint $table) {
                $table->unsignedBigInteger('room_id')->nullable()->change();
            });
        }
    }

    public function test_auto_assignment_does_not_reuse_a_room_already_assigned_to_the_same_booking(): void
    {
        $firstRoom = $this->createRoom('SAFE-101');
        $secondRoom = $this->createRoom('SAFE-102');
        $booking = $this->createBooking('confirmed');

        $this->createBookingRoom($booking, $firstRoom);
        $pendingLine = $this->createBookingRoom($booking);

        $result = app(RoomAssignmentService::class)->assignRoomToBooking($booking, 'Safety Test');

        $this->assertEmpty($result['unassigned']);
        $this->assertSame((int) $secondRoom->id, (int) $pendingLine->fresh()->room_id);
        $this->assertSame(2, $booking->bookingRooms()->distinct('room_id')->count('room_id'));
    }

    public function test_manual_assignment_rejects_a_room_already_assigned_to_the_same_booking(): void
    {
        $room = $this->createRoom('SAFE-201');
        $booking = $this->createBooking('confirmed');

        $this->createBookingRoom($booking, $room);
        $pendingLine = $this->createBookingRoom($booking);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ROOM_ALREADY_ASSIGNED_TO_BOOKING');

        app(RoomAssignmentService::class)->assignSpecificRoom(
            $booking,
            (int) $room->id,
            (int) $pendingLine->id,
            'Safety Test'
        );
    }

    public function test_availability_excludes_rooms_already_used_by_the_same_booking(): void
    {
        $assignedRoom = $this->createRoom('SAFE-301');
        $availableRoom = $this->createRoom('SAFE-302');
        $booking = $this->createBooking('confirmed');

        $this->createBookingRoom($booking, $assignedRoom);
        $this->createBookingRoom($booking);

        $rooms = app(RoomAssignmentService::class)->getAvailableRoomsByType(
            'deluxe',
            $booking->check_in,
            $booking->check_out,
            (int) $booking->id
        );

        $this->assertSame([(int) $availableRoom->id], $rooms->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_transfer_rejects_a_target_room_used_by_another_line_of_the_same_booking(): void
    {
        $currentRoom = $this->createRoom('SAFE-401', 'occupied');
        $otherAssignedRoom = $this->createRoom('SAFE-402', 'occupied');
        $booking = $this->createBooking('checked_in');
        $currentLine = $this->createBookingRoom($booking, $currentRoom);
        $this->createBookingRoom($booking, $otherAssignedRoom);
        $requester = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TARGET_ALREADY_ASSIGNED_TO_BOOKING');

        app(RoomTransferRequestService::class)->createRequest(
            $booking->id,
            [
                'booking_room_id' => $currentLine->id,
                'target_room_id' => $otherAssignedRoom->id,
                'reason' => 'Safety test transfer',
            ],
            $requester
        );
    }

    public function test_valid_transfer_completes_with_consistent_room_states(): void
    {
        Mail::fake();

        $currentRoom = $this->createRoom('SAFE-501', 'occupied');
        $targetRoom = $this->createRoom('SAFE-502');
        $booking = $this->createBooking('checked_in');
        $booking->update([
            'room_assignment_status' => 'assigned',
            'room_assigned_at' => now()->subHour(),
        ]);
        $bookingRoom = $this->createBookingRoom($booking, $currentRoom);
        $requester = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(RoomTransferRequestService::class);

        $request = $service->createRequest($booking->id, [
            'booking_room_id' => $bookingRoom->id,
            'target_room_id' => $targetRoom->id,
            'reason' => 'Valid safety test transfer',
        ], $requester);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'room_transfer_requested',
        ]);

        $service->approve((int) $request->id, $admin, 'Approved for safety test');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $requester->id,
            'type' => 'room_transfer_approved',
        ]);

        $result = $service->complete((int) $request->id, $admin, 'Guest moved');

        $this->assertSame('completed', $result['request']->status);
        $this->assertSame((int) $targetRoom->id, (int) $bookingRoom->fresh()->room_id);
        $this->assertSame('cleaning', $currentRoom->fresh()->status);
        $this->assertSame('occupied', $targetRoom->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $requester->id,
            'type' => 'room_transfer_completed',
        ]);
    }

    public function test_rejected_transfer_notifies_the_requesting_receptionist(): void
    {
        Mail::fake();

        $currentRoom = $this->createRoom('SAFE-601', 'occupied');
        $targetRoom = $this->createRoom('SAFE-602');
        $booking = $this->createBooking('checked_in');
        $bookingRoom = $this->createBookingRoom($booking, $currentRoom);
        $requester = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $service = app(RoomTransferRequestService::class);

        $request = $service->createRequest($booking->id, [
            'booking_room_id' => $bookingRoom->id,
            'target_room_id' => $targetRoom->id,
            'reason' => 'Guest requested a quieter room',
        ], $requester);

        $service->reject((int) $request->id, $admin, 'Target room is reserved');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $requester->id,
            'type' => 'room_transfer_rejected',
        ]);
    }

    private function createRoom(string $roomNumber, string $status = 'available'): Room
    {
        return Room::create([
            'room_number' => $roomNumber,
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 1,
            'status' => $status,
            'description' => 'Room allocation safety test',
        ]);
    }

    private function createBooking(string $status): Booking
    {
        return Booking::create([
            'check_in' => now()->addDays(2)->startOfDay(),
            'check_out' => now()->addDays(4)->startOfDay(),
            'number_of_guests' => 2,
            'booking_status' => $status,
            'reservation_status' => $status === 'checked_in' ? 'checked_in' : 'confirmed',
            'room_assignment_status' => 'pending_assignment',
            'total_amount' => 4000,
        ]);
    }

    private function createBookingRoom(Booking $booking, ?Room $room = null): BookingRoom
    {
        return BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room?->id,
            'requested_room_type' => 'deluxe',
            'price_per_night' => 1000,
            'nights' => 2,
            'subtotal' => 2000,
        ]);
    }
}
