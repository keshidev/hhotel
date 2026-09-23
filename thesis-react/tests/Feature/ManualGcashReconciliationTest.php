<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\ManualGcashReconciliation;
use App\Models\ManualGcashRefund;
use App\Models\ManualGcashSubmission;
use App\Models\CancellationApprovalRequest;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualGcashReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_summary_counts_reviews_reconciliation_and_refund_attention(): void
    {
        [$approved] = $this->createApprovedPayment('SUMMARY-APPROVED-1');
        $this->createPendingSubmission('SUMMARY-PENDING-1', false);
        $escalated = $this->createPendingSubmission('SUMMARY-OVERDUE-1', true);
        $escalated->update(['status' => ManualGcashSubmission::STATUS_ESCALATED]);
        [, $refundPayment] = $this->createApprovedPayment('SUMMARY-REFUND-1');
        $refundPayment->update(['lifecycle_status' => Payment::LIFECYCLE_REFUND_REQUIRED]);

        ManualGcashReconciliation::create([
            'submission_id' => $approved->id,
            'payment_id' => $approved->payment_id,
            'booking_id' => $approved->booking_id,
            'status' => ManualGcashReconciliation::STATUS_EXCEPTION_OPEN,
            'exception_type' => 'missing_transaction',
            'notes' => 'Transaction is missing from the daily statement.',
            'reconciled_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));

        $this->getJson('/api/manual-gcash-reconciliations/summary')
            ->assertOk()
            ->assertJson([
                'pending_verification' => 1,
                'escalated_review' => 1,
                'pending_review' => 2,
                'overdue_review' => 1,
                'refund_required' => 1,
                'unreconciled' => 1,
                'overdue_unreconciled' => 0,
                'open_exceptions' => 1,
            ]);
    }

    public function test_action_queues_group_open_work_and_completed_history(): void
    {
        $ready = $this->createPendingSubmission('QUEUE-READY-1', false);
        $admin = $this->createPendingSubmission('QUEUE-ADMIN-1', true);
        $admin->update(['status' => ManualGcashSubmission::STATUS_ESCALATED]);
        [$matched] = $this->createApprovedPayment('QUEUE-MATCHED-1');
        [$resolved] = $this->createApprovedPayment('QUEUE-RESOLVED-1');

        foreach ([
            [$matched, ManualGcashReconciliation::STATUS_MATCHED],
            [$resolved, ManualGcashReconciliation::STATUS_EXCEPTION_RESOLVED],
        ] as [$submission, $status]) {
            ManualGcashReconciliation::create([
                'submission_id' => $submission->id,
                'payment_id' => $submission->payment_id,
                'booking_id' => $submission->booking_id,
                'status' => $status,
                'reconciled_at' => now(),
            ]);
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->getJson('/api/manual-gcash-reviews?queue=ready')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->id);
        $this->getJson('/api/manual-gcash-reviews?queue=admin')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $admin->id);
        $this->getJson('/api/manual-gcash-reviews?queue=history')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/manual-gcash-reconciliations?queue=history')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_unreconciled_queue_exposes_server_calculated_deadline_and_overdue_summary(): void
    {
        config()->set([
            'payment.manual_gcash.monitoring.reconciliation_max_age_minutes' => 60,
            'payment.manual_gcash.monitoring.reconciliation_due_soon_minutes' => 15,
        ]);
        [$submission] = $this->createApprovedPayment('AGING-QUEUE-1001');
        $submission->forceFill(['reviewed_at' => now()->subMinutes(90)])->saveQuietly();

        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));

        $this->getJson('/api/manual-gcash-reconciliations/summary')
            ->assertOk()
            ->assertJson([
                'unreconciled' => 1,
                'overdue_unreconciled' => 1,
                'reconciliation_max_age_minutes' => 60,
            ]);

        $this->getJson('/api/manual-gcash-reconciliations?status=unreconciled')
            ->assertOk()
            ->assertJsonPath('data.0.submission_id', $submission->id)
            ->assertJsonPath('data.0.reconciliation_timing.status', 'overdue')
            ->assertJsonPath('data.0.reconciliation_timing.minutes_remaining', 0)
            ->assertJsonPath('data.0.reconciliation_timing.age_minutes', 90);
    }

    public function test_receptionist_can_reconcile_only_an_exact_statement_match(): void
    {
        [$submission, $payment] = $this->createApprovedPayment('EXACT-MATCH-1001');
        Sanctum::actingAs($receptionist = User::factory()->create(['role' => 'receptionist', 'status' => 'active']));

        $this->postJson("/api/manual-gcash-reconciliations/submissions/{$submission->id}/match", [
            'statement_reference' => 'WRONG-REFERENCE-1001',
            'statement_amount' => 500,
            'statement_paid_at' => $payment->paid_at->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('merchant_record');

        $this->assertDatabaseCount('manual_gcash_reconciliations', 0);

        $this->postJson("/api/manual-gcash-reconciliations/submissions/{$submission->id}/match", [
            'statement_reference' => 'exact match 1001',
            'statement_amount' => 500,
            'statement_paid_at' => $payment->paid_at->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
            'notes' => 'Matched against the official daily merchant statement.',
        ])->assertOk();

        $this->assertDatabaseHas('manual_gcash_reconciliations', [
            'submission_id' => $submission->id,
            'status' => ManualGcashReconciliation::STATUS_MATCHED,
            'matched_reference_claim' => 'EXACTMATCH1001',
            'reconciled_by' => $receptionist->id,
        ]);
        $this->assertSame(Payment::LIFECYCLE_PAID, $payment->fresh()->lifecycle_status);
    }

    public function test_exception_requires_admin_resolution_and_never_executes_a_refund(): void
    {
        [$submission, $payment] = $this->createApprovedPayment('EXCEPTION-2001');
        $receptionist = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        Sanctum::actingAs($receptionist);

        $this->postJson("/api/manual-gcash-reconciliations/submissions/{$submission->id}/exception", [
            'exception_type' => 'reversed_transaction',
            'notes' => 'The merchant statement marks this transaction as reversed.',
            'statement_reference' => 'EXCEPTION-2001',
            'statement_amount' => 500,
            'statement_paid_at' => $payment->paid_at->format('Y-m-d H:i:s'),
        ])->assertOk();

        $reconciliation = ManualGcashReconciliation::query()->firstOrFail();
        $this->assertSame(ManualGcashReconciliation::STATUS_EXCEPTION_OPEN, $reconciliation->status);
        $this->assertSame(Payment::LIFECYCLE_PAID_UNDER_REVIEW, $payment->fresh()->lifecycle_status);
        $this->assertSame('completed', $payment->fresh()->payment_status);

        $this->postJson("/api/manual-gcash-reconciliations/{$reconciliation->id}/resolve", [
            'resolution' => ManualGcashReconciliation::RESOLUTION_REFUND_REQUIRED,
            'resolution_notes' => 'A refund workflow is required after administrator review.',
        ])->assertForbidden();

        Sanctum::actingAs($administrator = User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->postJson("/api/manual-gcash-reconciliations/{$reconciliation->id}/resolve", [
            'resolution' => ManualGcashReconciliation::RESOLUTION_REFUND_REQUIRED,
            'resolution_notes' => 'A refund workflow is required after administrator review.',
        ])->assertOk();

        $this->assertDatabaseHas('manual_gcash_reconciliations', [
            'id' => $reconciliation->id,
            'status' => ManualGcashReconciliation::STATUS_EXCEPTION_RESOLVED,
            'resolution' => ManualGcashReconciliation::RESOLUTION_REFUND_REQUIRED,
            'resolved_by' => $administrator->id,
        ]);
        $this->assertSame(Payment::LIFECYCLE_REFUND_REQUIRED, $payment->fresh()->lifecycle_status);
        $this->assertSame('completed', $payment->fresh()->payment_status);
        $refundRequest = CancellationApprovalRequest::query()->where('booking_id', $payment->booking_id)->firstOrFail();
        $this->assertSame(CancellationApprovalRequest::STATUS_REFUND_PENDING, $refundRequest->status);
        $this->assertDatabaseHas('manual_gcash_refunds', [
            'cancellation_approval_request_id' => $refundRequest->id,
            'source_payment_id' => $payment->id,
            'status' => ManualGcashRefund::STATUS_APPROVED,
        ]);
        $this->assertDatabaseMissing('payments', [
            'booking_id' => $payment->booking_id,
            'payment_type' => 'refund',
        ]);
    }

    private function createApprovedPayment(string $reference): array
    {
        $reviewer = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $booking = $this->createBooking($reference);
        $paidAt = now()->subMinutes(10)->seconds(0);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'paid_amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'lifecycle_status' => Payment::LIFECYCLE_PAID,
            'provider' => 'manual_gcash',
            'provider_reference' => $reference,
            'paid_at' => $paidAt,
            'verified_by' => $reviewer->id,
            'verified_at' => now(),
        ]);
        $submission = ManualGcashSubmission::create([
            'payment_id' => $payment->id,
            'booking_id' => $booking->id,
            'attempt_number' => 1,
            'transaction_reference' => $reference,
            'normalized_transaction_reference' => $this->normalizeReference($reference),
            'active_reference_claim' => $this->normalizeReference($reference),
            'sender_name' => 'Reconciliation Guest',
            'submitted_amount' => 500,
            'paid_at' => $paidAt,
            'status' => ManualGcashSubmission::STATUS_APPROVED,
            'declaration_accepted_at' => now()->subMinutes(12),
            'proof_path' => 'tests/'.$this->normalizeReference($reference).'.png',
            'proof_original_name' => 'proof.png',
            'proof_mime_type' => 'image/png',
            'proof_size' => 100,
            'proof_sha256' => hash('sha256', $reference),
            'submitted_at' => now()->subMinutes(12),
            'review_due_at' => now()->subMinutes(2),
            'escalation_due_at' => now()->addHour(),
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now()->subMinutes(10),
            'review_reason' => 'Exact merchant record match.',
        ]);

        return [$submission, $payment, $booking];
    }

    private function createPendingSubmission(string $reference, bool $overdue): ManualGcashSubmission
    {
        $booking = $this->createBooking($reference);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
            'lifecycle_status' => Payment::LIFECYCLE_PENDING_VERIFICATION,
            'provider' => 'manual_gcash',
        ]);
        return ManualGcashSubmission::create([
            'payment_id' => $payment->id,
            'booking_id' => $booking->id,
            'attempt_number' => 1,
            'transaction_reference' => $reference,
            'normalized_transaction_reference' => $this->normalizeReference($reference),
            'active_reference_claim' => $this->normalizeReference($reference),
            'sender_name' => 'Pending Guest',
            'submitted_amount' => 500,
            'paid_at' => now()->subMinutes(10),
            'status' => ManualGcashSubmission::STATUS_PENDING,
            'declaration_accepted_at' => now()->subMinutes(10),
            'proof_path' => 'tests/'.$this->normalizeReference($reference).'.png',
            'proof_original_name' => 'proof.png',
            'proof_mime_type' => 'image/png',
            'proof_size' => 100,
            'proof_sha256' => hash('sha256', $reference),
            'submitted_at' => now()->subMinutes(10),
            'review_due_at' => $overdue ? now()->subMinute() : now()->addMinutes(10),
            'escalation_due_at' => now()->addHour(),
        ]);
    }

    private function createBooking(string $suffix): Booking
    {
        $booking = Booking::create([
            'reference_number' => 'BK-'.preg_replace('/[^A-Z0-9]/', '', strtoupper($suffix)),
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
            'name' => 'Reconciliation Guest',
            'email' => strtolower($suffix).'@example.com',
            'phone' => '09170000001',
            'is_primary' => true,
        ]);

        return $booking;
    }

    private function normalizeReference(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
    }
}
