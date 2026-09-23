<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\CancellationApprovalRequest;
use App\Models\ManualGcashRefund;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualGcashEvidenceRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manual_gcash_proofs');
        Storage::fake('manual_gcash_refunds');
        config()->set('payment.manual_gcash.proof_retention_days', 180);
    }

    public function test_expired_payment_proof_is_removed_but_accounting_and_audit_records_remain(): void
    {
        [$submission, $payment] = $this->createSubmission(
            status: ManualGcashSubmission::STATUS_APPROVED,
            lifecycle: Payment::LIFECYCLE_PAID,
            checkout: now()->subDays(181)
        );
        Storage::disk('manual_gcash_proofs')->put($submission->proof_path, 'payment-proof');

        $this->artisan('payments:manual-gcash-prune-evidence')->assertSuccessful();

        $submission->refresh();
        Storage::disk('manual_gcash_proofs')->assertMissing($submission->proof_path);
        $this->assertNotNull($submission->proof_deleted_at);
        $this->assertSame('retention_period_expired', $submission->proof_deletion_reason);
        $this->assertSame('RETENTIONPAYMENT1', $submission->normalized_transaction_reference);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'payment_status' => 'completed']);
        $this->assertTrue(ActivityLog::where('action_activity', 'Manual GCash Payment Proof Retired')->exists());

        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));
        $this->get("/api/manual-gcash-reviews/{$submission->id}/proof")->assertStatus(410);
        $this->getJson("/api/manual-gcash-reviews/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('proof.available', false)
            ->assertJsonPath('proof.url', null);
    }

    public function test_pending_or_disputed_payment_proof_is_never_removed(): void
    {
        [$submission] = $this->createSubmission(
            status: ManualGcashSubmission::STATUS_PENDING,
            lifecycle: Payment::LIFECYCLE_PENDING_VERIFICATION,
            checkout: now()->subDays(400)
        );
        Storage::disk('manual_gcash_proofs')->put($submission->proof_path, 'active-proof');

        $this->artisan('payments:manual-gcash-prune-evidence')->assertSuccessful();

        Storage::disk('manual_gcash_proofs')->assertExists($submission->proof_path);
        $this->assertNull($submission->fresh()->proof_deleted_at);
    }

    public function test_completed_refund_proof_is_removed_after_its_own_retention_period(): void
    {
        [$submission, $sourcePayment, $booking] = $this->createSubmission(
            status: ManualGcashSubmission::STATUS_APPROVED,
            lifecycle: Payment::LIFECYCLE_REFUNDED,
            checkout: now()->subDays(400)
        );
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $request = CancellationApprovalRequest::create([
            'booking_id' => $booking->id,
            'reason' => 'Completed manual GCash refund.',
            'refund_amount' => 500,
            'refund_method' => 'manual_gcash',
            'status' => CancellationApprovalRequest::STATUS_REFUNDED,
            'approved_by' => $admin->id,
            'approved_at' => now()->subDays(200),
            'refund_processed_at' => now()->subDays(181),
        ]);
        $refundPayment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => -500,
            'payment_type' => 'refund',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'provider' => 'manual_gcash',
            'paid_at' => now()->subDays(181),
        ]);
        $refund = ManualGcashRefund::create([
            'cancellation_approval_request_id' => $request->id,
            'booking_id' => $booking->id,
            'source_payment_id' => $sourcePayment->id,
            'refund_payment_id' => $refundPayment->id,
            'status' => ManualGcashRefund::STATUS_COMPLETED,
            'approved_amount' => 500,
            'approved_by' => $admin->id,
            'approved_at' => now()->subDays(200),
            'recipient_name' => 'Refund Guest',
            'recipient_account' => '09171234567',
            'recipient_account_last_four' => '4567',
            'gcash_reference' => 'REFUND-RETENTION-1',
            'normalized_gcash_reference' => 'REFUNDRETENTION1',
            'processed_at' => now()->subDays(181),
            'processed_by' => $admin->id,
            'reason' => 'Completed manual GCash refund.',
            'proof_disk' => 'manual_gcash_refunds',
            'proof_path' => 'refunds/retention-refund.png',
            'proof_original_name' => 'refund.png',
            'proof_mime_type' => 'image/png',
            'proof_size' => 12,
            'proof_sha256' => hash('sha256', 'refund-proof'),
        ]);
        Storage::disk('manual_gcash_proofs')->put($submission->proof_path, 'payment-proof');
        Storage::disk('manual_gcash_refunds')->put($refund->proof_path, 'refund-proof');

        $this->artisan('payments:manual-gcash-prune-evidence')->assertSuccessful();

        Storage::disk('manual_gcash_proofs')->assertMissing($submission->proof_path);
        Storage::disk('manual_gcash_refunds')->assertMissing($refund->proof_path);
        $this->assertNotNull($refund->fresh()->proof_deleted_at);
        $this->assertDatabaseHas('manual_gcash_refunds', [
            'id' => $refund->id,
            'normalized_gcash_reference' => 'REFUNDRETENTION1',
            'status' => ManualGcashRefund::STATUS_COMPLETED,
        ]);
    }

    public function test_dry_run_reports_expired_evidence_without_changing_files_or_records(): void
    {
        [$submission] = $this->createSubmission(
            status: ManualGcashSubmission::STATUS_APPROVED,
            lifecycle: Payment::LIFECYCLE_PAID,
            checkout: now()->subDays(181)
        );
        Storage::disk('manual_gcash_proofs')->put($submission->proof_path, 'payment-proof');

        $this->artisan('payments:manual-gcash-prune-evidence', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 eligible')
            ->assertSuccessful();

        Storage::disk('manual_gcash_proofs')->assertExists($submission->proof_path);
        $submission->refresh();
        $this->assertNull($submission->proof_deleted_at);
        $this->assertNull($submission->proof_disposal_started_at);
        $this->assertFalse(ActivityLog::where('action_activity', 'Manual GCash Payment Proof Retired')->exists());
    }

    public function test_migrations_recover_when_submission_columns_exist_but_refund_table_is_missing(): void
    {
        Schema::drop('manual_gcash_refunds');

        $ensureRefundTable = require database_path('migrations/2026_08_22_000004_z_ensure_manual_gcash_refunds_table.php');
        $ensureRefundTable->up();

        $retentionMigration = require database_path('migrations/2026_08_22_000005_add_retention_tracking_to_manual_gcash_evidence.php');
        $retentionMigration->up();

        $this->assertTrue(Schema::hasTable('manual_gcash_refunds'));
        $this->assertTrue(Schema::hasColumns('manual_gcash_submissions', [
            'proof_retention_expires_at',
            'proof_disposal_started_at',
            'proof_deleted_at',
            'proof_deletion_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('manual_gcash_refunds', [
            'proof_retention_expires_at',
            'proof_disposal_started_at',
            'proof_deleted_at',
            'proof_deletion_reason',
        ]));
    }

    private function createSubmission(string $status, string $lifecycle, $checkout): array
    {
        $booking = Booking::create([
            'reference_number' => 'BKRETENTION'.str_pad((string) (Booking::count() + 1), 4, '0', STR_PAD_LEFT),
            'check_in' => $checkout->copy()->subDay(),
            'check_out' => $checkout,
            'number_of_guests' => 1,
            'booking_status' => $status === ManualGcashSubmission::STATUS_PENDING ? 'confirmed' : 'checked_out',
            'reservation_status' => $status === ManualGcashSubmission::STATUS_PENDING ? 'confirmed' : 'completed',
            'total_amount' => 1000,
            'downpayment_percentage' => 50,
        ]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'paid_amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => $status === ManualGcashSubmission::STATUS_PENDING ? 'pending' : 'completed',
            'lifecycle_status' => $lifecycle,
            'provider' => 'manual_gcash',
            'paid_at' => $checkout->copy()->subDay(),
        ]);
        $sequence = ManualGcashSubmission::count() + 1;
        $contents = 'payment-proof';
        $submission = ManualGcashSubmission::create([
            'payment_id' => $payment->id,
            'booking_id' => $booking->id,
            'attempt_number' => 1,
            'transaction_reference' => 'RETENTION-PAYMENT-'.$sequence,
            'normalized_transaction_reference' => 'RETENTIONPAYMENT'.$sequence,
            'active_reference_claim' => $status === ManualGcashSubmission::STATUS_PENDING ? 'RETENTIONPAYMENT'.$sequence : null,
            'sender_name' => 'Retention Guest',
            'submitted_amount' => 500,
            'paid_at' => $checkout->copy()->subDay(),
            'status' => $status,
            'declaration_accepted_at' => $checkout->copy()->subDay(),
            'proof_disk' => 'manual_gcash_proofs',
            'proof_path' => 'submissions/retention-'.$sequence.'.png',
            'proof_original_name' => 'proof.png',
            'proof_mime_type' => 'image/png',
            'proof_size' => strlen($contents),
            'proof_sha256' => hash('sha256', $contents),
            'submitted_at' => $checkout->copy()->subDay(),
            'review_due_at' => $checkout->copy()->subDay()->addMinutes(15),
            'escalation_due_at' => $checkout->copy()->subDay()->addHours(2),
            'reviewed_at' => $status === ManualGcashSubmission::STATUS_PENDING ? null : $checkout->copy()->subDay(),
        ]);

        return [$submission, $payment, $booking];
    }
}
