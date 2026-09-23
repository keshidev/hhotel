<?php

namespace Tests\Feature;

use App\Mail\NoShowNotification;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NoShowSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-27 19:00:00'));
        SystemSetting::writeMany(['no_show_cutoff_time' => '18:00']);

        $this->receptionist = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->receptionist);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_no_show_preserves_maintenance_room_and_records_contact_evidence(): void
    {
        $room = $this->createRoom('201', 'maintenance');
        $booking = $this->createConfirmedBooking($room);

        $this->postJson("/api/bookings/{$booking->id}/no-show", $this->validPayload())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('already_processed', false);

        $booking->refresh();

        $this->assertSame('no_show', $booking->booking_status);
        $this->assertSame('no_show', $booking->reservation_status);
        $this->assertSame($this->receptionist->id, $booking->no_show_marked_by);
        $this->assertSame('phone', $booking->no_show_contact_method);
        $this->assertSame('no_response', $booking->no_show_contact_outcome);
        $this->assertSame('18:00', $booking->no_show_cutoff_time);
        $this->assertSame('maintenance', $room->fresh()->status);
        Mail::assertQueued(NoShowNotification::class);
    }

    public function test_no_show_does_not_release_room_occupied_by_another_checked_in_booking(): void
    {
        $room = $this->createRoom('202', 'occupied');
        $targetBooking = $this->createConfirmedBooking($room);
        $occupyingBooking = $this->createConfirmedBooking($room, [
            'reference_number' => 'OCCUPIED-BOOKING',
            'booking_status' => 'checked_in',
            'reservation_status' => 'checked_in',
        ]);

        $this->postJson("/api/bookings/{$targetBooking->id}/no-show", $this->validPayload())
            ->assertOk();

        $this->assertSame('no_show', $targetBooking->fresh()->booking_status);
        $this->assertSame('checked_in', $occupyingBooking->fresh()->booking_status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_paid_no_show_requires_manual_financial_review_and_revokes_payment_access(): void
    {
        $room = $this->createRoom('203');
        $booking = $this->createConfirmedBooking($room);
        $this->createCompletedPayment($booking, 750);
        DB::table('payment_access_sessions')->insert([
            'booking_id' => $booking->id,
            'token_hash' => hash('sha256', str_repeat('a', 40)),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/bookings/{$booking->id}/no-show", $this->validPayload())
            ->assertOk();

        $booking->refresh();
        $session = DB::table('payment_access_sessions')->where('booking_id', $booking->id)->first();

        $this->assertSame('manual_review_required', $booking->no_show_financial_disposition);
        $this->assertSame('750.00', $booking->no_show_financial_amount);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame('booking_no_show', $session->revocation_reason);
    }

    public function test_repeated_no_show_request_is_idempotent(): void
    {
        $room = $this->createRoom('204');
        $booking = $this->createConfirmedBooking($room);

        $this->postJson("/api/bookings/{$booking->id}/no-show", $this->validPayload())
            ->assertOk()
            ->assertJsonPath('already_processed', false);

        $this->postJson("/api/bookings/{$booking->id}/no-show", $this->validPayload())
            ->assertOk()
            ->assertJsonPath('already_processed', true);

        Mail::assertQueued(NoShowNotification::class, 1);
        $this->assertSame(1, ActivityLog::query()
            ->where('action_activity', 'Booking Marked as No-Show')
            ->where('model_id', $booking->id)
            ->count());
    }

    public function test_no_show_is_rejected_before_configured_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 17:59:00'));
        $room = $this->createRoom('205');
        $booking = $this->createConfirmedBooking($room);

        $this->postJson("/api/bookings/{$booking->id}/no-show", $this->validPayload([
            'contacted_at' => '2026-08-27 17:50:00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_SHOW_BEFORE_CUTOFF');

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame('available', $room->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_no_show_requires_complete_contact_evidence(): void
    {
        $booking = $this->createConfirmedBooking($this->createRoom('206'));

        $this->postJson("/api/bookings/{$booking->id}/no-show", [
            'contacted_guest' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_method', 'contacted_at', 'contact_outcome']);

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
    }

    public function test_no_show_rejects_contact_evidence_from_before_check_in_date(): void
    {
        $booking = $this->createConfirmedBooking($this->createRoom('208'));

        $this->postJson("/api/bookings/{$booking->id}/no-show", $this->validPayload([
            'contacted_at' => '2026-08-26 18:45:00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CONTACT_TIME')
            ->assertJsonPath('message', 'The guest contact time cannot be before the check-in date.');

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        Mail::assertNothingQueued();
    }

    public function test_missing_booking_returns_not_found(): void
    {
        $this->postJson('/api/bookings/999999/no-show', $this->validPayload())
            ->assertNotFound()
            ->assertJsonPath('message', 'Booking not found.');
    }

    public function test_no_show_without_guest_email_reports_manual_notification_requirement(): void
    {
        $booking = $this->createConfirmedBooking($this->createRoom('207'), guestEmail: '');

        $this->postJson("/api/bookings/{$booking->id}/no-show", $this->validPayload())
            ->assertOk()
            ->assertJsonPath('notification_status', 'not_applicable')
            ->assertJsonPath('message', 'Booking marked as No-Show. No guest email is available; please notify the guest manually.');

        Mail::assertNothingQueued();
        $this->assertSame('not_applicable', $booking->fresh()->no_show_email_status);
    }

    private function createRoom(string $roomNumber, string $status = 'available'): Room
    {
        return Room::create([
            'room_number' => $roomNumber,
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1500,
            'floor' => 2,
            'status' => $status,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createConfirmedBooking(
        Room $room,
        array $overrides = [],
        ?string $guestEmail = 'no-show@example.com'
    ): Booking {
        $booking = Booking::create(array_merge([
            'check_in' => '2026-08-27 00:00:00',
            'check_out' => '2026-08-28 12:00:00',
            'number_of_guests' => 1,
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'room_assignment_status' => 'assigned',
            'room_assigned_at' => now()->subDay(),
            'total_amount' => 1500,
            'booking_source' => 'online',
            'stay_type' => 'overnight',
            'duration_hours' => 36,
        ], $overrides));

        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'No-Show Guest',
            'email' => $guestEmail,
            'phone' => '09170000000',
            'is_primary' => true,
        ]);

        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'requested_room_type' => $room->room_type,
            'price_per_night' => $room->price_per_night,
            'nights' => 1,
            'subtotal' => $room->price_per_night,
            'room_status' => 'active',
        ]);

        return $booking;
    }

    private function createCompletedPayment(Booking $booking, float $amount): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'amount' => $amount,
            'payment_type' => Payment::TYPE_DOWNPAYMENT,
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'lifecycle_status' => Payment::LIFECYCLE_PAID,
            'paid_at' => now()->subHour(),
            'verified_at' => now()->subHour(),
            'verified_by' => $this->receptionist->id,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'contacted_guest' => true,
            'contact_method' => 'phone',
            'contacted_at' => '2026-08-27 18:45:00',
            'contact_outcome' => 'no_response',
            'contact_notes' => 'Called twice and sent an SMS.',
        ], $overrides);
    }
}
