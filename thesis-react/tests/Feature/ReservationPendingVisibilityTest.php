<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationPendingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));
        config(['payment.manual_gcash.max_submission_attempts' => 3]);
    }

    private function booking(string $source = 'online', string $status = 'pending'): Booking
    {
        $booking = Booking::create([
            'check_in' => now()->addDay(), 'check_out' => now()->addDays(2),
            'number_of_guests' => 1, 'booking_status' => $status,
            'reservation_status' => $status === 'pending' ? 'pending_payment' : $status,
            'booking_source' => $source, 'total_amount' => 1000,
        ]);
        BookingGuest::create([
            'booking_id' => $booking->id, 'name' => 'Reservation Test Guest',
            'email' => 'reservation@example.test', 'phone' => '09170000001', 'is_primary' => true,
        ]);
        return $booking;
    }

    private function payment(Booking $booking, string $status = 'pending', string $type = 'downpayment'): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id, 'amount' => 500, 'payment_type' => $type,
            'payment_method' => 'gcash', 'payment_status' => $status, 'provider' => 'manual_gcash',
            'payment_due_at' => now()->addMinutes(30), 'submission_attempts' => 1,
        ]);
    }

    private function proof(Payment $payment, string $status = 'pending_verification', int $attempt = 1): ManualGcashSubmission
    {
        return ManualGcashSubmission::create([
            'booking_id' => $payment->booking_id, 'payment_id' => $payment->id,
            'attempt_number' => $attempt, 'transaction_reference' => 'TEST123456',
            'normalized_transaction_reference' => 'TEST123456', 'sender_name' => 'Test Guest',
            'submitted_amount' => 500, 'paid_at' => now(), 'status' => $status,
            'declaration_accepted_at' => now(), 'proof_path' => 'test-only.png',
            'proof_original_name' => 'test-only.png', 'proof_mime_type' => 'image/png',
            'proof_size' => 1, 'proof_sha256' => str_repeat('0', 64),
            'submitted_at' => now(), 'review_due_at' => now()->addMinutes(15),
            'escalation_due_at' => now()->addHours(2),
        ]);
    }

    public function test_directory_includes_submitted_pending_but_excludes_abandoned_and_refund_only_bookings(): void
    {
        $unsubmitted = $this->booking();
        $this->payment($unsubmitted);
        $submitted = $this->booking();
        $this->proof($this->payment($submitted));
        $walkIn = $this->booking('walk_in');
        $paid = $this->booking('online', 'confirmed');
        $this->payment($paid, 'completed');
        $refund = $this->booking();
        $this->proof($this->payment($refund, 'completed', 'refund'));
        $closed = $this->booking('online', 'cancelled');
        $this->proof($this->payment($closed, 'failed'), 'rejected');

        $rows = $this->getJson('/api/receptionist/reservations')->assertOk()->json('reservations');
        $this->assertEqualsCanonicalizing(
            [$submitted->reference_number, $walkIn->reference_number, $paid->reference_number],
            array_column($rows, 'id')
        );
        $row = collect($rows)->firstWhere('id', $submitted->reference_number);
        $this->assertSame('Pending', $row['status']);
        $this->assertSame('UNPAID', $row['paymentStatus']);
        $this->assertEquals(0, $row['totalPaid']);
        $this->assertEquals(1000, $row['remainingBalance']);
        $this->assertSame('Awaiting verification', $row['paymentReview']['label']);
        $this->assertSame('online', $row['bookingSource']);
        $this->assertFalse(Booking::visibleToStaff()->whereKey($submitted->id)->exists());
        $this->getJson('/api/receptionist/reservations/'.$submitted->reference_number)->assertOk();
        $this->getJson('/api/receptionist/reservations/'.$unsubmitted->reference_number)->assertNotFound();
        $this->getJson('/api/receptionist/reservations?status=confirmed')->assertOk()->assertJsonCount(1, 'reservations');
        $this->getJson('/api/receptionist/reservations?status=pending')->assertOk()->assertJsonCount(2, 'reservations');
        $this->getJson('/api/receptionist/reservations?search='.$submitted->reference_number)->assertOk()->assertJsonCount(1, 'reservations');
    }

    public function test_review_labels_follow_latest_proof_retry_deadline_and_escalation(): void
    {
        $booking = $this->booking();
        $payment = $this->payment($booking);
        $proof = $this->proof($payment, 'rejected');
        $url = '/api/receptionist/reservations/'.$booking->reference_number;
        $this->getJson($url)->assertOk()->assertJsonPath('paymentReview.label', 'Awaiting corrected proof')->assertJsonPath('paymentReview.queue', 'history');
        $payment->update(['payment_due_at' => now()->subMinute()]);
        $this->getJson($url)->assertOk()->assertJsonPath('paymentReview.label', 'Proof rejected');
        $this->proof($payment, 'escalated', 2);
        $this->getJson($url)->assertOk()->assertJsonPath('paymentReview.label', 'Needs administrator')->assertJsonPath('paymentReview.queue', 'admin');
        $this->proof($payment, 'pending_verification', 3);
        $this->getJson($url)->assertOk()->assertJsonPath('paymentReview.label', 'Awaiting verification')->assertJsonPath('paymentReview.queue', 'ready');
        $payment->update(['payment_status' => 'completed']);
        $this->getJson($url)->assertOk()->assertJsonPath('paymentReview', null)->assertJsonPath('paymentStatus', 'PARTIAL');
    }
}