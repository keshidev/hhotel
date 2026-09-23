<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomAssignmentService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingInventoryHoldIntegrityTest extends TestCase
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

    public function test_public_availability_counts_pending_room_type_holds(): void
    {
        $firstRoom = $this->createRoom('HOLD-101', 'deluxe', 2, true);
        $this->createRoom('HOLD-102', 'deluxe', 2, true);
        $checkIn = now()->addDays(5)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();

        $booking = $this->createBooking($checkIn, $checkOut, 'pending', now()->addMinutes(30));
        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => null,
            'requested_room_type' => 'deluxe',
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
        ]);

        $response = $this->getJson('/api/client/rooms/available?'.http_build_query([
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'number_of_guests' => 2,
        ]))->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_type', 'deluxe')
            ->assertJsonPath('data.0.availability_count', 1);
        $this->assertSame((int) $firstRoom->id, (int) $response->json('data.0.id'));
    }

    public function test_public_availability_only_returns_rooms_that_fit_the_entire_party(): void
    {
        $this->createRoom('FIT-101', 'superior_twin', 2, true);
        $largeRoom = $this->createRoom('FIT-201', 'executive_suite', 4, true);
        $checkIn = now()->addDays(6)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();

        $response = $this->getJson('/api/client/rooms/available?'.http_build_query([
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'number_of_guests' => 3,
        ]))->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_type', 'executive_suite')
            ->assertJsonPath('data.0.capacity', 4);
        $this->assertSame((int) $largeRoom->id, (int) $response->json('data.0.id'));
    }

    public function test_calendar_marks_a_date_fully_booked_when_a_pending_type_hold_uses_the_last_room(): void
    {
        $this->createRoom('CAL-101', 'premier', 2, true);
        $checkIn = now()->addDays(8)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();
        $booking = $this->createBooking($checkIn, $checkOut, 'pending', now()->addMinutes(30));

        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => null,
            'requested_room_type' => 'premier',
            'price_per_night' => 1500,
            'nights' => 1,
            'subtotal' => 1500,
        ]);

        $this->getJson('/api/client/rooms/availability-calendar?'.http_build_query([
            'month' => $checkIn->month,
            'year' => $checkIn->year,
        ]))->assertOk()
            ->assertJsonPath('data.'.$checkIn->toDateString(), 'fully_booked');
    }

    public function test_type_availability_respects_a_room_line_extended_checkout(): void
    {
        $room = $this->createRoom('EXT-101', 'superior_twin', 2, true);
        $checkIn = now()->addDays(10)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();
        $booking = $this->createBooking($checkIn, $checkOut, 'confirmed');

        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'requested_room_type' => 'superior_twin',
            'price_per_night' => 1200,
            'nights' => 1,
            'subtotal' => 1200,
            'extended_checkout' => $checkOut->copy()->addDay(),
        ]);

        $available = app(RoomAssignmentService::class)->countAvailableRoomsByType(
            roomType: 'superior_twin',
            checkIn: $checkOut->copy()->addHours(2),
            checkOut: $checkOut->copy()->addHours(6),
            websiteOnly: true
        );

        $this->assertSame(0, $available);
    }

    public function test_walk_in_cannot_bypass_a_pending_online_room_type_hold(): void
    {
        $room = $this->createRoom('WALK-101', 'executive_suite', 2, true);
        $checkIn = now()->addDays(6)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();
        $onlineBooking = $this->createBooking($checkIn, $checkOut, 'pending', now()->addMinutes(30));

        BookingRoom::create([
            'booking_id' => $onlineBooking->id,
            'room_id' => null,
            'requested_room_type' => 'executive_suite',
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $this->postJson('/api/receptionist/walk-in', [
            'guest_name' => 'Walk In Guest',
            'guest_email' => 'walk-in@example.com',
            'guest_phone' => '09170000001',
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 1,
            'room_ids' => [$room->id],
            'payment_method' => 'cash',
            'amount_tendered' => 2000,
            'idempotency_key' => (string) Str::uuid(),
            'stay_type' => 'overnight',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'One or more selected rooms are no longer available for the selected dates.');

        $this->assertDatabaseMissing('bookings', [
            'booking_source' => 'walk_in',
            'check_in' => $checkIn->toDateTimeString(),
        ]);
    }

    public function test_walk_in_search_does_not_offer_a_room_assigned_to_an_active_pending_booking(): void
    {
        $heldRoom = $this->createRoom('WALK-201', 'deluxe', 2, true);
        $availableRoom = $this->createRoom('WALK-202', 'deluxe', 2, true);
        $checkIn = now()->addDays(7)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();
        $onlineBooking = $this->createBooking($checkIn, $checkOut, 'pending', now()->addMinutes(30));

        BookingRoom::create([
            'booking_id' => $onlineBooking->id,
            'room_id' => $heldRoom->id,
            'requested_room_type' => 'deluxe',
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $response = $this->getJson('/api/receptionist/rooms/available?'.http_build_query([
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 1,
            'stay_type' => 'overnight',
        ]))->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $availableRoom->id);
        $this->assertNotContains($heldRoom->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_walk_in_cannot_select_a_room_assigned_to_an_active_pending_booking(): void
    {
        $heldRoom = $this->createRoom('WALK-301', 'superior_queen', 2, true);
        $this->createRoom('WALK-302', 'superior_queen', 2, true);
        $checkIn = now()->addDays(9)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();
        $onlineBooking = $this->createBooking($checkIn, $checkOut, 'pending', now()->addMinutes(30));

        BookingRoom::create([
            'booking_id' => $onlineBooking->id,
            'room_id' => $heldRoom->id,
            'requested_room_type' => 'superior_queen',
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $this->postJson('/api/receptionist/walk-in', [
            'guest_name' => 'Pending Room Guest',
            'guest_email' => 'pending-room@example.com',
            'guest_phone' => '09170000002',
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 1,
            'room_ids' => [$heldRoom->id],
            'payment_method' => 'cash',
            'amount_tendered' => 2000,
            'idempotency_key' => (string) Str::uuid(),
            'stay_type' => 'overnight',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'One or more selected rooms are no longer available for the selected dates.');

        $this->assertDatabaseMissing('bookings', [
            'booking_source' => 'walk_in',
            'check_in' => $checkIn->toDateTimeString(),
            'created_by' => auth()->id(),
        ]);
    }

    public function test_walk_in_cannot_select_a_room_under_maintenance_when_another_room_type_unit_is_available(): void
    {
        $maintenanceRoom = $this->createRoom('WALK-401', 'premier', 2, true);
        $maintenanceRoom->update(['status' => 'maintenance']);
        $this->createRoom('WALK-402', 'premier', 2, true);
        $checkIn = now()->addDays(11)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $this->postJson('/api/receptionist/walk-in', [
            'guest_name' => 'Maintenance Room Guest',
            'guest_email' => 'maintenance-room@example.com',
            'guest_phone' => '09170000003',
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 1,
            'room_ids' => [$maintenanceRoom->id],
            'payment_method' => 'cash',
            'amount_tendered' => 2000,
            'idempotency_key' => (string) Str::uuid(),
            'stay_type' => 'overnight',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'One or more selected rooms are no longer available for the selected dates.');

        $this->assertDatabaseMissing('bookings', [
            'booking_source' => 'walk_in',
            'check_in' => $checkIn->toDateTimeString(),
            'created_by' => auth()->id(),
        ]);
    }

    public function test_walk_in_rejects_guest_count_above_selected_room_capacity(): void
    {
        $room = $this->createRoom('WALK-501', 'deluxe', 2, true);
        $checkIn = now()->addDays(12)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $this->postJson('/api/receptionist/walk-in', [
            'guest_name' => 'Capacity Guest',
            'guest_email' => 'capacity@example.com',
            'guest_phone' => '09170000004',
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 3,
            'room_ids' => [$room->id],
            'payment_method' => 'cash',
            'amount_tendered' => 2000,
            'idempotency_key' => (string) Str::uuid(),
            'stay_type' => 'overnight',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('number_of_guests')
            ->assertJsonPath(
                'message',
                'The selected rooms can accommodate only 2 guest(s). Please select more rooms or reduce the guest count.'
            );

        $this->assertDatabaseMissing('bookings', [
            'booking_source' => 'walk_in',
            'check_in' => $checkIn->toDateTimeString(),
        ]);
    }

    public function test_walk_in_accepts_guest_count_within_combined_selected_room_capacity(): void
    {
        $firstRoom = $this->createRoom('WALK-601', 'superior_twin', 2, true);
        $secondRoom = $this->createRoom('WALK-602', 'superior_twin', 2, true);
        $checkIn = now()->addDays(13)->startOfDay();
        $checkOut = $checkIn->copy()->addDay();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $response = $this->postJson('/api/receptionist/walk-in', [
            'guest_name' => 'Combined Capacity Guest',
            'guest_email' => 'combined-capacity@example.com',
            'guest_phone' => '09170000005',
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 3,
            'room_ids' => [$firstRoom->id, $secondRoom->id],
            'payment_method' => 'cash',
            'amount_tendered' => 2000,
            'idempotency_key' => (string) Str::uuid(),
            'stay_type' => 'overnight',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $bookingId = (int) $response->json('data.booking_id');
        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'booking_source' => 'walk_in',
            'number_of_guests' => 3,
        ]);
        $this->assertSame(2, BookingRoom::where('booking_id', $bookingId)->count());
    }

    public function test_walk_in_search_and_creation_reject_check_in_older_than_grace_period(): void
    {
        config()->set('bookings.walk_in_check_in_grace_minutes', 15);
        $this->travelTo(Carbon::parse('2026-08-29 10:00:00'));

        $room = $this->createRoom('WALK-701', 'deluxe', 2, true);
        $checkIn = now()->subMinutes(16);
        $checkOut = now()->addDay();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $message = 'Check-in cannot be more than 15 minutes in the past. Please use the current time or a future time.';

        $this->getJson('/api/receptionist/rooms/available?'.http_build_query([
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 1,
            'stay_type' => 'overnight',
        ]))->assertUnprocessable()
            ->assertJsonPath('message', $message)
            ->assertJsonValidationErrors('check_in');

        $this->postJson('/api/receptionist/walk-in', [
            'guest_name' => 'Past Walk In Guest',
            'guest_email' => 'past-walk-in@example.com',
            'guest_phone' => '09170000006',
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 1,
            'room_ids' => [$room->id],
            'payment_method' => 'cash',
            'amount_tendered' => 2000,
            'idempotency_key' => (string) Str::uuid(),
            'stay_type' => 'overnight',
        ])->assertUnprocessable()
            ->assertJsonPath('message', $message)
            ->assertJsonValidationErrors('check_in');

        $this->assertDatabaseMissing('bookings', [
            'booking_source' => 'walk_in',
            'check_in' => $checkIn->toDateTimeString(),
        ]);

        $this->travelBack();
    }

    public function test_walk_in_accepts_check_in_within_grace_period(): void
    {
        config()->set('bookings.walk_in_check_in_grace_minutes', 15);
        $this->travelTo(Carbon::parse('2026-08-29 10:00:00'));

        $room = $this->createRoom('WALK-702', 'deluxe', 2, true);
        $checkIn = now()->subMinutes(10);
        $checkOut = now()->addDay();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $response = $this->postJson('/api/receptionist/walk-in', [
            'guest_name' => 'Grace Period Guest',
            'guest_email' => 'grace-period@example.com',
            'guest_phone' => '09170000007',
            'check_in' => $checkIn->toDateTimeString(),
            'check_out' => $checkOut->toDateTimeString(),
            'number_of_guests' => 1,
            'room_ids' => [$room->id],
            'payment_method' => 'cash',
            'amount_tendered' => 2000,
            'idempotency_key' => (string) Str::uuid(),
            'stay_type' => 'overnight',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('bookings', [
            'id' => (int) $response->json('data.booking_id'),
            'booking_source' => 'walk_in',
            'check_in' => $checkIn->toDateTimeString(),
        ]);

        $this->travelBack();
    }

    public function test_expiry_rechecks_locked_payment_state_before_cancelling(): void
    {
        $booking = $this->createBooking(
            now()->addDays(3)->startOfDay(),
            now()->addDays(4)->startOfDay(),
            'pending',
            now()->subMinute()
        );
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
            'provider' => 'manual_gcash',
            'lifecycle_status' => Payment::LIFECYCLE_AWAITING_PAYMENT,
        ]);

        $paymentSettledAfterSelection = false;
        Event::listen('eloquent.retrieved: '.Booking::class, function (Booking $retrieved) use (
            $booking,
            $payment,
            &$paymentSettledAfterSelection
        ) {
            if ($paymentSettledAfterSelection || (int) $retrieved->id !== (int) $booking->id) {
                return;
            }

            $paymentSettledAfterSelection = true;
            DB::table('payments')->where('id', $payment->id)->update([
                'payment_status' => 'completed',
                'lifecycle_status' => Payment::LIFECYCLE_PAID,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->artisan('bookings:expire-abandoned')->assertSuccessful();

        $this->assertTrue($paymentSettledAfterSelection);
        $this->assertSame('pending', $booking->fresh()->booking_status);
        $this->assertSame('completed', $payment->fresh()->payment_status);
        $this->assertDatabaseMissing('cancellation_approval_requests', [
            'booking_id' => $booking->id,
        ]);
    }

    private function createRoom(
        string $roomNumber,
        string $roomType,
        int $capacity,
        bool $showOnWebsite
    ): Room {
        return Room::create([
            'room_number' => $roomNumber,
            'room_type' => $roomType,
            'capacity' => $capacity,
            'price_per_night' => 1000,
            'floor' => 1,
            'status' => 'available',
            'show_on_website' => $showOnWebsite,
            'description' => 'Inventory integrity test room',
        ]);
    }

    private function createBooking(
        $checkIn,
        $checkOut,
        string $status,
        $expiresAt = null
    ): Booking {
        return Booking::create([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'number_of_guests' => 1,
            'booking_status' => $status,
            'reservation_status' => $status,
            'room_assignment_status' => $status === 'pending' ? 'pending_assignment' : 'assigned',
            'expires_at' => $expiresAt,
            'total_amount' => 1000,
        ]);
    }
}
