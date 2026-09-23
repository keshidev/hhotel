<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Services\RoomAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoomAssignmentConcurrencyTest extends TestCase
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

    public function test_auto_assignment_is_atomic_and_does_not_reuse_an_overlapping_room(): void
    {
        $room = $this->createRoom('LOCK-101');
        [$firstBooking, $firstLine] = $this->createPendingAssignmentBooking();
        [$secondBooking, $secondLine] = $this->createPendingAssignmentBooking();
        $baselineTransactionLevel = DB::transactionLevel();
        $assignmentTransactionLevels = [];

        BookingRoom::updating(function (BookingRoom $line) use ($firstLine, &$assignmentTransactionLevels) {
            if ((int) $line->id === (int) $firstLine->id && $line->isDirty('room_id')) {
                $assignmentTransactionLevels[] = DB::transactionLevel();
            }
        });

        $service = app(RoomAssignmentService::class);
        $firstResult = $service->assignRoomToBooking($firstBooking, 'Concurrency Test');
        $secondResult = $service->assignRoomToBooking($secondBooking, 'Concurrency Test');

        $this->assertCount(1, $firstResult['assigned']);
        $this->assertEmpty($firstResult['unassigned']);
        $this->assertEmpty($secondResult['assigned']);
        $this->assertSame('no_available_room', $secondResult['unassigned'][0]['reason']);
        $this->assertNotEmpty($assignmentTransactionLevels);
        $this->assertGreaterThan($baselineTransactionLevel, min($assignmentTransactionLevels));
        $this->assertSame((int) $room->id, (int) $firstLine->fresh()->room_id);
        $this->assertNull($secondLine->fresh()->room_id);
        $this->assertSame(1, BookingRoom::query()->where('room_id', $room->id)->count());
    }

    public function test_manual_assignment_is_atomic_and_rechecks_room_overlap(): void
    {
        $room = $this->createRoom('LOCK-102');
        [$firstBooking, $firstLine] = $this->createPendingAssignmentBooking();
        [$secondBooking, $secondLine] = $this->createPendingAssignmentBooking();
        $baselineTransactionLevel = DB::transactionLevel();
        $assignmentTransactionLevel = null;

        BookingRoom::updating(function (BookingRoom $line) use ($firstLine, &$assignmentTransactionLevel) {
            if ((int) $line->id === (int) $firstLine->id && $line->isDirty('room_id')) {
                $assignmentTransactionLevel = DB::transactionLevel();
            }
        });

        $service = app(RoomAssignmentService::class);
        $service->assignSpecificRoom($firstBooking, (int) $room->id, (int) $firstLine->id, 'Concurrency Test');

        try {
            $service->assignSpecificRoom($secondBooking, (int) $room->id, (int) $secondLine->id, 'Concurrency Test');
            $this->fail('Expected the overlapping manual room assignment to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ROOM_OVERLAP', $exception->getMessage());
        }

        $this->assertNotNull($assignmentTransactionLevel);
        $this->assertGreaterThan($baselineTransactionLevel, $assignmentTransactionLevel);
        $this->assertSame((int) $room->id, (int) $firstLine->fresh()->room_id);
        $this->assertNull($secondLine->fresh()->room_id);
    }

    public function test_overlapping_booking_receives_the_next_locked_available_room(): void
    {
        $firstRoom = $this->createRoom('LOCK-201');
        $secondRoom = $this->createRoom('LOCK-202');
        [$firstBooking, $firstLine] = $this->createPendingAssignmentBooking();
        [$secondBooking, $secondLine] = $this->createPendingAssignmentBooking();
        $service = app(RoomAssignmentService::class);

        $service->assignRoomToBooking($firstBooking, 'Concurrency Test');
        $secondResult = $service->assignRoomToBooking($secondBooking, 'Concurrency Test');

        $this->assertSame((int) $firstRoom->id, (int) $firstLine->fresh()->room_id);
        $this->assertSame((int) $secondRoom->id, (int) $secondLine->fresh()->room_id);
        $this->assertCount(1, $secondResult['assigned']);
        $this->assertEmpty($secondResult['unassigned']);
    }

    private function createRoom(string $roomNumber): Room
    {
        return Room::create([
            'room_number' => $roomNumber,
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000.00,
            'floor' => 1,
            'status' => 'available',
            'description' => 'Concurrency test room',
        ]);
    }

    private function createPendingAssignmentBooking(): array
    {
        $booking = Booking::create([
            'check_in' => now()->addDays(10)->startOfDay(),
            'check_out' => now()->addDays(12)->startOfDay(),
            'number_of_guests' => 1,
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'room_assignment_status' => 'pending_assignment',
            'total_amount' => 2000.00,
        ]);

        $line = BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => null,
            'requested_room_type' => 'deluxe',
            'price_per_night' => 1000.00,
            'nights' => 2,
            'subtotal' => 2000.00,
        ]);

        return [$booking, $line];
    }
}
