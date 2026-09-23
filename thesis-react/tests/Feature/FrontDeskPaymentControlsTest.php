<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontDeskPaymentControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_receptionist_can_search_the_shared_payment_ledger(): void
    {
        $booking = $this->createBooking('SHARED-LEDGER');
        $cash = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'paid_amount' => 500,
            'payment_type' => 'balance_payment',
            'payment_method' => 'cash',
            'payment_status' => 'completed',
            'provider' => 'manual',
            'paid_at' => now(),
        ]);
        Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
            'provider' => 'manual_gcash',
        ]);

        foreach (['admin', 'receptionist'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->getJson('/api/payment-records?method=cash&status=Completed')
                ->assertOk()
                ->assertJsonCount(1, 'payments')
                ->assertJsonPath('payments.0.numeric_id', $cash->id)
                ->assertJsonPath('stats.total', 2)
                ->assertJsonPath('stats.pending', 1)
                ->assertJsonPath('stats.accepted', 1);
        }
    }

    public function test_balance_settlement_rejects_card_and_bank_transfer(): void
    {
        $booking = $this->createBooking('UNSUPPORTED');
        $this->actingAsReceptionist();

        foreach (['card', 'bank_transfer'] as $method) {
            $this->postJson("/api/receptionist/bookings/{$booking->id}/settle-balance", [
                'amount' => 1000,
                'payment_method' => $method,
                'notes' => 'Unsupported method test',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_front_desk_gcash_settlement_is_reconciliable_and_idempotent(): void
    {
        Storage::fake('manual_gcash_proofs');
        $booking = $this->createBooking('GCASH');
        $this->actingAsReceptionist();
        $key = (string) Str::uuid();
        $payload = [
            'amount' => 1000,
            'payment_method' => 'gcash',
            'notes' => 'Balance paid at front desk.',
            'idempotency_key' => $key,
            'gcash_reference' => 'FRONT-DESK-REF-1001',
            'gcash_sender_name' => 'Front Desk Guest',
            'gcash_paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
        ];

        $this->postJson("/api/receptionist/bookings/{$booking->id}/settle-balance", $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false);
        $this->postJson("/api/receptionist/bookings/{$booking->id}/settle-balance", $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('manual_gcash_submissions', 1);
        $payment = Payment::firstOrFail();
        $submission = ManualGcashSubmission::firstOrFail();
        $this->assertSame('manual_gcash', $payment->provider);
        $this->assertSame(Payment::LIFECYCLE_PAID, $payment->lifecycle_status);
        $this->assertSame(ManualGcashSubmission::STATUS_APPROVED, $submission->status);
        $this->assertSame(ManualGcashSubmission::SOURCE_FRONT_DESK, $submission->submission_source);
        Storage::disk('manual_gcash_proofs')->assertExists($submission->proof_path);

        $this->getJson('/api/manual-gcash-reconciliations?status=unreconciled')
            ->assertOk()
            ->assertJsonPath('data.0.submission_id', $submission->id)
            ->assertJsonPath('data.0.submission_source', ManualGcashSubmission::SOURCE_FRONT_DESK);
    }

    public function test_duplicate_gcash_reference_is_rejected_across_bookings(): void
    {
        Storage::fake('manual_gcash_proofs');
        $first = $this->createBooking('FIRST');
        $second = $this->createBooking('SECOND');
        $this->actingAsReceptionist();

        $base = [
            'amount' => 1000,
            'payment_method' => 'gcash',
            'notes' => 'Verified merchant payment.',
            'gcash_reference' => 'DUPLICATE-REF-2002',
            'gcash_sender_name' => 'Duplicate Guest',
            'gcash_paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
        ];
        $this->postJson("/api/receptionist/bookings/{$first->id}/settle-balance", $base + [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->postJson("/api/receptionist/bookings/{$second->id}/settle-balance", $base + [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('gcash_reference');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('manual_gcash_submissions', 1);
    }

    public function test_cash_settlement_does_not_create_gcash_reconciliation_record(): void
    {
        $booking = $this->createBooking('CASH');
        $this->actingAsReceptionist();

        $this->postJson("/api/receptionist/bookings/{$booking->id}/settle-balance", [
            'amount' => 1000,
            'payment_method' => 'cash',
            'notes' => 'Cash collected at front desk.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'payment_method' => 'cash',
            'provider' => 'manual',
            'payment_status' => 'completed',
        ]);
        $this->assertDatabaseCount('manual_gcash_submissions', 0);
    }

    public function test_walk_in_rejects_bank_transfer(): void
    {
        $this->actingAsReceptionist();

        $this->postJson('/api/receptionist/walk-in', [
            'payment_method' => 'bank_transfer',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    private function actingAsReceptionist(): User
    {
        $user = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createBooking(string $suffix): Booking
    {
        $room = Room::create([
            'room_number' => 'FD-'.$suffix,
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'available',
            'description' => 'Front desk payment test room',
        ]);
        $booking = Booking::create([
            'reference_number' => 'BK-FD-'.$suffix,
            'check_in' => now()->addDay(),
            'check_out' => now()->addDays(2),
            'number_of_guests' => 1,
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'total_amount' => 1000,
            'downpayment_percentage' => 50,
        ]);
        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Front Desk Guest',
            'email' => strtolower($suffix).'@example.com',
            'phone' => '09170000001',
            'is_primary' => true,
        ]);
        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
        ]);

        return $booking;
    }
}
