<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\EarlyCheckInRequest;
use App\Models\Payment;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckInSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-25 15:00:00'));
        SystemSetting::writeMany(['check_in_time' => '15:00']);

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

    public function test_completed_payment_and_assigned_rooms_are_required_for_check_in(): void
    {
        $room = $this->createRoom('201');
        $booking = $this->createConfirmedBooking([$room], createPayment: false);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot check in. A completed payment must be verified first.');

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_partial_payment_is_blocked_until_original_balance_is_settled(): void
    {
        $room = $this->createRoom('202');
        $booking = $this->createConfirmedBooking([$room], createPayment: false);
        $this->createCompletedPayment($booking, 750, Payment::TYPE_DOWNPAYMENT);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('code', 'BALANCE_REQUIRED')
            ->assertJsonPath('remaining_balance', 750);

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_completed_refunds_reduce_the_net_amount_available_for_check_in(): void
    {
        $room = $this->createRoom('203');
        $booking = $this->createConfirmedBooking([$room], createPayment: false);
        $this->createCompletedPayment($booking, 1500, Payment::TYPE_FULL_PAYMENT);
        $this->createCompletedPayment($booking, 500, Payment::TYPE_REFUND, 'refunded');

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('code', 'BALANCE_REQUIRED')
            ->assertJsonPath('remaining_balance', 500);

        $this->assertSame(1000.0, $booking->fresh()->total_paid);
    }

    public function test_balance_collection_is_idempotent_and_unlocks_check_in(): void
    {
        $room = $this->createRoom('204');
        $booking = $this->createConfirmedBooking([$room], createPayment: false);
        $this->createCompletedPayment($booking, 750, Payment::TYPE_DOWNPAYMENT);
        $requestKey = (string) Str::uuid();
        $payload = [
            'amount' => 750,
            'payment_method' => 'cash',
            'notes' => 'Remaining balance collected at check-in',
            'idempotency_key' => $requestKey,
        ];

        $this->postJson("/api/receptionist/bookings/{$booking->id}/settle-balance", $payload)
            ->assertOk()
            ->assertJsonPath('data.remaining_balance', 0)
            ->assertJsonPath('data.replayed', false);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/settle-balance", $payload)
            ->assertOk()
            ->assertJsonPath('data.remaining_balance', 0)
            ->assertJsonPath('data.replayed', true);

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'payment_type' => Payment::TYPE_BALANCE_PAYMENT,
            'payment_status' => 'completed',
            'amount' => 750,
            'staff_recording_key' => $requestKey,
        ]);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('checked_in', $booking->fresh()->booking_status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_successful_check_in_records_actor_time_and_occupies_every_room(): void
    {
        $firstRoom = $this->createRoom('301');
        $secondRoom = $this->createRoom('302');
        $booking = $this->createConfirmedBooking([$firstRoom, $secondRoom]);

        $this->postJson("/api/receptionist/bookings/{$booking->reference_number}/check-in")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('early_check_in', false);

        $booking->refresh();

        $this->assertSame('checked_in', $booking->booking_status);
        $this->assertSame('checked_in', $booking->reservation_status);
        $this->assertSame($this->receptionist->id, $booking->checked_in_by);
        $this->assertSame('2026-08-25 15:00:00', $booking->checked_in_at?->format('Y-m-d H:i:s'));
        $this->assertNull($booking->early_check_in_reason);
        $this->assertSame('occupied', $firstRoom->fresh()->status);
        $this->assertSame('occupied', $secondRoom->fresh()->status);
    }

    public function test_check_in_is_blocked_when_an_assigned_room_has_an_active_checked_in_booking(): void
    {
        $room = $this->createRoom('401');
        $occupyingBooking = $this->createConfirmedBooking([$room]);
        $occupyingBooking->update([
            'booking_status' => 'checked_in',
            'reservation_status' => 'checked_in',
        ]);

        $room->update(['status' => 'available']);
        $targetBooking = $this->createConfirmedBooking([$room]);

        $this->postJson("/api/receptionist/bookings/{$targetBooking->id}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('message', "Cannot check in because an assigned room is still occupied by booking {$occupyingBooking->reference_number}.");

        $this->assertSame('confirmed', $targetBooking->fresh()->booking_status);
    }

    public function test_check_in_is_blocked_after_the_checkout_window(): void
    {
        $room = $this->createRoom('501');
        $booking = $this->createConfirmedBooking([$room], [
            'check_in' => '2026-08-23 15:00:00',
            'check_out' => '2026-08-25 14:59:59',
        ]);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('message', "Cannot check in because this booking's stay ended on Aug 25, 2026 2:59 PM.");

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
    }

    public function test_regular_check_in_opens_at_3pm(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 14:59:00'));
        $room = $this->createRoom('550');
        $booking = $this->createConfirmedBooking([$room]);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('code', 'EARLY_APPROVAL_REQUIRED')
            ->assertJsonPath('check_in_time', '3:00 PM');

        Carbon::setTestNow(Carbon::parse('2026-08-25 15:00:00'));

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertOk()
            ->assertJsonPath('early_check_in', false);

        $this->assertSame('checked_in', $booking->fresh()->booking_status);
    }

    public function test_early_check_in_requires_and_consumes_admin_approval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 13:00:00'));
        $room = $this->createRoom('601');
        $booking = $this->createConfirmedBooking([$room]);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('code', 'EARLY_APPROVAL_REQUIRED')
            ->assertJsonPath('check_in_time', '3:00 PM');

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in", [
            'force_early_checkin' => true,
            'early_check_in_reason' => 'Room inspected and guest arrived on an early flight.',
        ])->assertStatus(422)->assertJsonPath('code', 'EARLY_APPROVAL_REQUIRED');

        $reason = 'Room inspected and guest arrived on an early flight.';
        $this->postJson("/api/receptionist/bookings/{$booking->id}/early-check-in-request", [
            'reason' => $reason,
        ])->assertCreated()->assertJsonPath('code', 'EARLY_APPROVAL_PENDING');

        $earlyRequest = EarlyCheckInRequest::query()->sole();
        $this->assertSame(EarlyCheckInRequest::STATUS_PENDING, $earlyRequest->status);
        $this->assertSame($this->receptionist->id, $earlyRequest->requested_by);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('code', 'EARLY_APPROVAL_PENDING');

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/early-check-in-requests/{$earlyRequest->id}/approve", [
            'decision_note' => 'Approved after confirming that the assigned room is ready.',
        ])->assertOk()->assertJsonPath('request.status', EarlyCheckInRequest::STATUS_APPROVED);

        Sanctum::actingAs($this->receptionist);
        $this->postJson("/api/receptionist/bookings/{$booking->id}/check-in")
            ->assertOk()
            ->assertJsonPath('early_check_in', true);

        $booking->refresh();
        $earlyRequest->refresh();
        $this->assertSame($reason, $booking->early_check_in_reason);
        $this->assertSame($this->receptionist->id, $booking->checked_in_by);
        $this->assertSame(EarlyCheckInRequest::STATUS_CONSUMED, $earlyRequest->status);
        $this->assertSame($admin->id, $earlyRequest->decided_by);
        $this->assertSame($this->receptionist->id, $earlyRequest->consumed_by);
        $this->assertNull($earlyRequest->active_slot);
    }

    private function createRoom(string $roomNumber): Room
    {
        return Room::create([
            'room_number' => $roomNumber,
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1500,
            'floor' => 2,
            'status' => 'available',
        ]);
    }

    /**
     * @param array<int, Room> $rooms
     * @param array<string, mixed> $overrides
     */
    private function createConfirmedBooking(array $rooms, array $overrides = [], bool $createPayment = true): Booking
    {
        $booking = Booking::create(array_merge([
            'check_in' => '2026-08-25 00:00:00',
            'check_out' => '2026-08-26 12:00:00',
            'number_of_guests' => count($rooms),
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'room_assignment_status' => 'assigned',
            'room_assigned_at' => now()->subDay(),
            'total_amount' => 1500 * count($rooms),
            'booking_source' => 'online',
            'stay_type' => 'overnight',
            'duration_hours' => 36,
        ], $overrides));

        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Check-In Guest',
            'email' => 'checkin-' . $booking->id . '@example.com',
            'phone' => '09170000000',
            'is_primary' => true,
        ]);

        foreach ($rooms as $room) {
            BookingRoom::create([
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'requested_room_type' => $room->room_type,
                'price_per_night' => $room->price_per_night,
                'nights' => 1,
                'subtotal' => $room->price_per_night,
                'room_status' => 'active',
            ]);
        }

        if ($createPayment) {
            $this->createCompletedPayment(
                $booking,
                1500 * count($rooms),
                Payment::TYPE_FULL_PAYMENT
            );
        }

        return $booking;
    }

    private function createCompletedPayment(
        Booking $booking,
        float $amount,
        string $paymentType,
        string $paymentStatus = 'completed'
    ): Payment {
        return Payment::create([
            'booking_id' => $booking->id,
            'amount' => $amount,
            'payment_type' => $paymentType,
            'payment_method' => 'gcash',
            'payment_status' => $paymentStatus,
            'lifecycle_status' => $paymentType === Payment::TYPE_REFUND
                ? Payment::LIFECYCLE_REFUNDED
                : Payment::LIFECYCLE_PAID,
            'paid_at' => now()->subHour(),
            'verified_at' => now()->subHour(),
            'verified_by' => $this->receptionist->id,
        ]);
    }
}
