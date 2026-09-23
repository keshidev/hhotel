<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\Feedback;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use App\Services\CheckoutStayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckOutSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-25 12:30:00'));
        Mail::fake();

        $this->receptionist = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->receptionist);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_checkout_is_blocked_until_the_remaining_balance_is_settled(): void
    {
        [$booking, $room] = $this->createCheckedInBooking(500);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-out")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Checkout blocked: remaining balance must be settled first.')
            ->assertJsonPath('remaining_balance', 500);

        $this->assertSame('checked_in', $booking->fresh()->booking_status);
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertNull($booking->fresh()->checked_out_at);
    }

    public function test_checkout_records_actor_and_time_then_moves_room_to_cleaning(): void
    {
        [$booking, $room, $bookingRoom] = $this->createCheckedInBooking(1000);

        $this->postJson("/api/receptionist/bookings/{$booking->reference_number}/check-out")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('replayed', false);

        $booking->refresh();
        $bookingRoom->refresh();

        $this->assertSame('checked_out', $booking->booking_status);
        $this->assertSame('completed', $booking->reservation_status);
        $this->assertSame($this->receptionist->id, $booking->checked_out_by);
        $this->assertSame('2026-08-25 12:30:00', $booking->checked_out_at?->format('Y-m-d H:i:s'));
        $this->assertSame('checked_out', $bookingRoom->room_status);
        $this->assertSame('2026-08-25 12:30:00', $bookingRoom->checked_out_at?->format('Y-m-d H:i:s'));
        $this->assertSame('cleaning', $room->fresh()->status);
        $this->assertSame(1, Feedback::where('booking_id', $booking->id)->count());
    }

    public function test_retrying_a_completed_checkout_returns_success_without_duplicate_effects(): void
    {
        [$booking] = $this->createCheckedInBooking(1000);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-out")
            ->assertOk()
            ->assertJsonPath('replayed', false);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-out")
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('message', 'Guest was already checked out.');

        $this->assertSame(1, Feedback::where('booking_id', $booking->id)->count());
    }

    public function test_final_per_room_checkout_records_actor_time_and_cleaning_state(): void
    {
        [$booking, $room, $bookingRoom] = $this->createCheckedInBooking(1000);

        $this->postJson("/api/bookings/{$booking->id}/rooms/{$room->id}/checkout", [
            'booking_room_id' => $bookingRoom->id,
        ])->assertOk();

        $booking->refresh();

        $this->assertSame('checked_out', $booking->booking_status);
        $this->assertSame('completed', $booking->reservation_status);
        $this->assertSame($this->receptionist->id, $booking->checked_out_by);
        $this->assertSame('2026-08-25 12:30:00', $booking->checked_out_at?->format('Y-m-d H:i:s'));
        $this->assertSame('checked_out', $bookingRoom->fresh()->room_status);
        $this->assertSame('cleaning', $room->fresh()->status);
    }

    public function test_non_final_room_checkout_is_blocked_when_booking_has_a_remaining_balance(): void
    {
        [$booking, $room, $bookingRoom] = $this->createCheckedInBooking(500);

        $secondRoom = Room::create([
            'room_number' => 'CO-' . random_int(2000, 2999),
            'room_type' => 'superior_queen',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'occupied',
        ]);
        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $secondRoom->id,
            'requested_room_type' => $secondRoom->room_type,
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
            'room_status' => 'active',
        ]);
        $booking->update(['total_amount' => 2000]);

        $this->postJson("/api/bookings/{$booking->id}/rooms/{$room->id}/checkout", [
            'booking_room_id' => $bookingRoom->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Checkout blocked: remaining balance must be settled first.')
            ->assertJsonPath('remaining_balance', 1500);

        $this->assertSame('checked_in', $booking->fresh()->booking_status);
        $this->assertSame('active', $bookingRoom->fresh()->room_status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_per_room_checkout_rejects_inline_extra_charges_before_mutating_booking(): void
    {
        [$booking, $room, $bookingRoom] = $this->createCheckedInBooking(1000);

        $this->postJson("/api/bookings/{$booking->id}/rooms/{$room->id}/checkout", [
            'booking_room_id' => $bookingRoom->id,
            'extra_charges' => 250,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Save extra charges and collect the updated balance before checking out this room.'
            );

        $this->assertSame('checked_in', $booking->fresh()->booking_status);
        $this->assertSame(1000.0, (float) $booking->fresh()->total_amount);
        $this->assertSame('active', $bookingRoom->fresh()->room_status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_repeated_extensions_charge_only_the_new_increment_without_undercharging(): void
    {
        [$booking, $room, $bookingRoom] = $this->createCheckedInBooking(1000);

        $this->postJson("/api/bookings/{$booking->id}/rooms/{$room->id}/extend", [
            'booking_room_id' => $bookingRoom->id,
            'new_checkout_date' => '2026-08-26',
            'reason' => 'Guest requested another night.',
        ])->assertOk();

        $this->postJson("/api/bookings/{$booking->id}/rooms/{$room->id}/extend", [
            'booking_room_id' => $bookingRoom->id,
            'new_checkout_date' => '2026-08-27',
            'reason' => 'Guest requested one more night.',
        ])->assertOk();

        $booking->refresh();
        $bookingRoom->refresh();

        $this->assertSame(3000.0, (float) $booking->total_amount);
        $this->assertSame(3000.0, (float) $bookingRoom->subtotal);
        $this->assertSame(2000.0, (float) $bookingRoom->extension_charge);
        $this->assertSame(3, (int) $bookingRoom->nights);
        $this->assertSame('2026-08-27 12:00:00', $bookingRoom->extended_checkout?->format('Y-m-d H:i:s'));
    }

    public function test_extending_one_room_does_not_extend_an_unmodified_sibling_room(): void
    {
        [$booking, $room, $bookingRoom] = $this->createCheckedInBooking(2000);
        $booking->update(['total_amount' => 2000]);

        $secondRoom = Room::create([
            'room_number' => 'CO-' . random_int(1000, 1999),
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'occupied',
        ]);
        $secondLine = BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $secondRoom->id,
            'requested_room_type' => $secondRoom->room_type,
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
            'room_status' => 'active',
        ]);

        $this->postJson("/api/bookings/{$booking->id}/rooms/{$room->id}/extend", [
            'booking_room_id' => $bookingRoom->id,
            'new_checkout_date' => '2026-08-26',
            'reason' => 'Only the first room needs another night.',
        ])->assertOk();

        $effectiveSiblingCheckout = app(CheckoutStayService::class)->effectiveCheckoutAt(
            $secondLine->fresh(),
            $booking->fresh(),
        );

        $this->assertSame('2026-08-25 12:00:00', $effectiveSiblingCheckout->format('Y-m-d H:i:s'));
    }

    /**
     * @return array{Booking, Room, BookingRoom}
     */
    private function createCheckedInBooking(float $paidAmount): array
    {
        $room = Room::create([
            'room_number' => 'CO-' . random_int(100, 999),
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'occupied',
        ]);

        $booking = Booking::create([
            'reference_number' => Booking::generateReferenceNumber(),
            'check_in' => '2026-08-24 15:00:00',
            'check_out' => '2026-08-25 12:00:00',
            'number_of_guests' => 1,
            'booking_status' => 'checked_in',
            'reservation_status' => 'checked_in',
            'room_assignment_status' => 'assigned',
            'room_assigned_at' => now()->subDay(),
            'checked_in_at' => now()->subDay(),
            'checked_in_by' => $this->receptionist->id,
            'total_amount' => 1000,
            'booking_source' => 'online',
            'stay_type' => 'overnight',
            'duration_hours' => 21,
        ]);

        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Check-Out Guest',
            'email' => 'checkout-' . $booking->id . '@example.com',
            'phone' => '09170000000',
            'is_primary' => true,
        ]);

        $bookingRoom = BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'requested_room_type' => $room->room_type,
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
            'room_status' => 'active',
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $paidAmount,
            'payment_type' => $paidAmount >= 1000 ? 'full_payment' : 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'lifecycle_status' => Payment::LIFECYCLE_PAID,
            'paid_at' => now()->subDay(),
            'verified_at' => now()->subDay(),
            'verified_by' => $this->receptionist->id,
        ]);

        return [$booking, $room, $bookingRoom];
    }
}
