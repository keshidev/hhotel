<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomManagementIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_room_cannot_start_as_occupied(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/rooms', $this->roomPayload([
            'room_number' => 'RM-101',
            'status' => 'occupied',
        ]))->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('rooms', ['room_number' => 'RM-101']);
    }

    public function test_occupied_status_cannot_be_set_manually(): void
    {
        $room = $this->createRoom(['room_number' => 'RM-102']);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/rooms/{$room->id}/status", [
            'status' => 'occupied',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_future_assignment_blocks_structural_and_maintenance_changes(): void
    {
        $room = $this->createRoom(['room_number' => 'RM-103']);
        $this->assignBooking($room, 'confirmed');
        $this->actingAsAdmin();

        $this->putJson("/api/admin/rooms/{$room->id}", $this->roomPayload([
            'room_number' => 'RM-999',
            'status' => 'available',
        ]))->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->patchJson("/api/admin/rooms/{$room->id}/status", [
            'status' => 'maintenance',
        ])->assertStatus(409)
            ->assertJsonPath('success', false);

        $room->refresh();
        $this->assertSame('RM-103', $room->room_number);
        $this->assertSame('available', $room->status);
    }

    public function test_safe_commercial_update_remains_allowed_for_future_assignment(): void
    {
        $room = $this->createRoom([
            'room_number' => 'RM-104',
            'room_type' => 'family',
            'capacity' => 5,
            'price_per_night' => 3500,
        ]);
        $this->assignBooking($room, 'confirmed');
        $this->actingAsAdmin();

        $this->putJson("/api/admin/rooms/{$room->id}", $this->roomPayload([
            'room_number' => 'RM-104',
            'room_type' => 'family',
            'capacity' => 5,
            'price_per_night' => 3600,
            'description' => 'Updated future catalog description',
        ]))->assertOk();

        $room->refresh();
        $this->assertSame('3600.00', $room->price_per_night);
        $this->assertSame('Updated future catalog description', $room->description);
    }

    public function test_checked_in_room_cannot_be_forced_available(): void
    {
        $room = $this->createRoom([
            'room_number' => 'RM-105',
            'status' => 'occupied',
        ]);
        $this->assignBooking($room, 'checked_in');
        $this->actingAsAdmin();

        $this->putJson("/api/admin/rooms/{$room->id}", $this->roomPayload([
            'room_number' => 'RM-105',
            'status' => 'available',
        ]))->assertStatus(409);

        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_rejected_update_does_not_delete_existing_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('rooms/original.jpg', 'image-data');
        $room = $this->createRoom([
            'room_number' => 'RM-106',
            'images' => ['rooms/original.jpg'],
        ]);
        $this->assignBooking($room, 'confirmed');
        $this->actingAsAdmin();

        $this->putJson("/api/admin/rooms/{$room->id}", $this->roomPayload([
            'room_number' => 'RM-106-CHANGED',
        ]))->assertStatus(409);

        Storage::disk('public')->assertExists('rooms/original.jpg');
        $this->assertSame(['rooms/original.jpg'], $room->fresh()->images);
    }

    public function test_bulk_delete_is_atomic_when_one_room_has_a_protected_assignment(): void
    {
        $protectedRoom = $this->createRoom(['room_number' => 'RM-107']);
        $freeRoom = $this->createRoom(['room_number' => 'RM-108']);
        $this->assignBooking($protectedRoom, 'confirmed');
        $this->actingAsAdmin();

        $this->postJson('/api/admin/rooms/bulk-delete', [
            'ids' => [$freeRoom->id, $protectedRoom->id],
        ])->assertStatus(409);

        $this->assertDatabaseHas('rooms', ['id' => $protectedRoom->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('rooms', ['id' => $freeRoom->id, 'deleted_at' => null]);
    }

    public function test_unassigned_room_delete_commits_before_removing_its_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('rooms/delete-me.jpg', 'image-data');
        $room = $this->createRoom([
            'room_number' => 'RM-109',
            'images' => ['rooms/delete-me.jpg'],
        ]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
        Storage::disk('public')->assertMissing('rooms/delete-me.jpg');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function createRoom(array $overrides = []): Room
    {
        return Room::create(array_merge([
            'room_number' => 'RM-' . fake()->unique()->numberBetween(200, 999),
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1500,
            'price_day_tour' => 975,
            'floor' => 2,
            'status' => 'available',
            'description' => 'Room integrity test',
            'amenities' => [],
            'images' => [],
            'show_on_website' => true,
        ], $overrides));
    }

    private function assignBooking(Room $room, string $status): Booking
    {
        $booking = Booking::create([
            'reference_number' => 'BK-RM-' . strtoupper(fake()->unique()->bothify('????####')),
            'check_in' => now()->addDay(),
            'check_out' => now()->addDays(2),
            'number_of_guests' => 2,
            'booking_status' => $status,
            'reservation_status' => $status === 'checked_in' ? 'checked-in' : 'confirmed',
            'total_amount' => 1500,
            'booking_source' => 'online',
        ]);

        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'price_per_night' => 1500,
            'nights' => 1,
            'subtotal' => 1500,
            'room_status' => 'active',
        ]);

        return $booking;
    }

    private function roomPayload(array $overrides = []): array
    {
        return array_merge([
            'room_number' => 'RM-DEFAULT',
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1500,
            'price_day_tour' => 975,
            'floor' => 2,
            'status' => 'available',
            'description' => 'Room integrity test',
            'amenities' => [],
            'show_on_website' => true,
        ], $overrides);
    }
}
