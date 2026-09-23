<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CancellationApprovalRequest;
use App\Models\ManualGcashReconciliation;
use App\Models\ManualGcashRefund;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\User;
use App\Services\ManualGcashConfigurationService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualGcashOperationalMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://hhotelbooking.com/api',
            'app.frontend_url' => 'https://hhotelbooking.com',
            'payment.provider' => 'manual_gcash',
            'payment.manual_gcash.implemented' => true,
            'payment.manual_gcash.monitoring.reconciliation_max_age_minutes' => 1440,
            'payment.manual_gcash.monitoring.reconciliation_due_soon_minutes' => 240,
            'payment.manual_gcash.monitoring.reconciliation_exception_max_age_minutes' => 1440,
            'payment.manual_gcash.monitoring.refund_max_age_minutes' => 1440,
            'payment.manual_gcash.monitoring.queue_job_max_age_minutes' => 15,
            'payment.manual_gcash.launch.merchant_verified' => true,
            'payment.manual_gcash.launch.staff_trained' => true,
            'payment.manual_gcash.launch.backup_confirmed' => true,
            'payment.manual_gcash.launch.rollback_reviewed' => true,
            'payment.manual_gcash.launch.production_approved' => true,
            'queue.default' => 'database',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.encryption' => 'tls',
            'mail.mailers.smtp.username' => 'hotel@example.com',
            'mail.mailers.smtp.password' => 'secret-password',
            'mail.from.address' => 'hotel@example.com',
            'mail.from.name' => 'H+ Hotel',
        ]);

        $manualConfiguration = $this->mock(ManualGcashConfigurationService::class);
        $manualConfiguration->shouldReceive('issues')->zeroOrMoreTimes()->andReturn([]);

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        app(SchedulerHeartbeatService::class)->record();
    }

    public function test_health_endpoint_and_command_report_healthy_operations_without_exposing_details(): void
    {
        $response = $this->getJson('/api/system/manual-gcash-health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->artisan('payments:manual-gcash-operations-health')
            ->expectsOutput('Manual GCash operations are healthy.')
            ->assertSuccessful();
    }

    public function test_normal_pending_review_remains_healthy_before_escalation_deadline(): void
    {
        $this->createSubmission(ManualGcashSubmission::STATUS_PENDING, now()->addHour());

        $this->getJson('/api/system/manual-gcash-health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_escalated_review_degrades_health_and_command_fails(): void
    {
        $this->createSubmission(ManualGcashSubmission::STATUS_ESCALATED, now()->subMinute());

        $this->getJson('/api/system/manual-gcash-health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded']);

        $this->artisan('payments:manual-gcash-operations-health')
            ->expectsOutputToContain('overdue_proof_reviews')
            ->assertFailed();
    }

    public function test_recent_unreconciled_payment_remains_healthy(): void
    {
        $submission = $this->createSubmission(ManualGcashSubmission::STATUS_APPROVED, now()->addHour());
        $submission->forceFill(['reviewed_at' => now()->subHour()])->saveQuietly();

        $this->getJson('/api/system/manual-gcash-health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_stale_unreconciled_payment_degrades_health_and_command_fails(): void
    {
        $submission = $this->createSubmission(ManualGcashSubmission::STATUS_APPROVED, now()->subDays(2));
        $submission->forceFill(['reviewed_at' => now()->subDays(2)])->saveQuietly();

        $this->getJson('/api/system/manual-gcash-health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded']);

        $this->artisan('payments:manual-gcash-operations-health')
            ->expectsOutputToContain('stale_unreconciled_payments')
            ->assertFailed();
    }

    public function test_stale_available_queue_job_degrades_health(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->timestamp,
            'created_at' => now()->subMinutes(20)->timestamp,
        ]);

        $this->getJson('/api/system/manual-gcash-health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded']);
    }

    public function test_stale_reconciliation_exception_degrades_health(): void
    {
        $submission = $this->createSubmission(ManualGcashSubmission::STATUS_APPROVED, now()->subDays(2));
        $reconciliation = ManualGcashReconciliation::create([
            'submission_id' => $submission->id,
            'payment_id' => $submission->payment_id,
            'booking_id' => $submission->booking_id,
            'status' => ManualGcashReconciliation::STATUS_EXCEPTION_OPEN,
            'exception_type' => 'missing_transaction',
            'notes' => 'Monitoring test exception',
        ]);
        $reconciliation->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->getJson('/api/system/manual-gcash-health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded']);
    }

    public function test_stale_incomplete_refund_degrades_health(): void
    {
        $submission = $this->createSubmission(ManualGcashSubmission::STATUS_APPROVED, now()->subDays(2));
        $request = CancellationApprovalRequest::create([
            'booking_id' => $submission->booking_id,
            'reason' => 'Monitoring test cancellation',
            'refund_amount' => 500,
            'refund_method' => 'gcash',
            'status' => CancellationApprovalRequest::STATUS_REFUND_PENDING,
        ]);

        ManualGcashRefund::create([
            'cancellation_approval_request_id' => $request->id,
            'booking_id' => $submission->booking_id,
            'source_payment_id' => $submission->payment_id,
            'status' => ManualGcashRefund::STATUS_APPROVED,
            'approved_amount' => 500,
            'approved_at' => now()->subDays(2),
        ]);

        $this->getJson('/api/system/manual-gcash-health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded']);
    }

    private function createSubmission(string $status, $escalationDueAt): ManualGcashSubmission
    {
        $booking = Booking::create([
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'number_of_guests' => 1,
            'booking_status' => 'pending',
            'reservation_status' => 'pending_payment',
            'expires_at' => now()->addHours(2),
            'total_amount' => 1000,
            'downpayment_percentage' => 50,
        ]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
            'lifecycle_status' => Payment::LIFECYCLE_PENDING_VERIFICATION,
            'provider' => 'manual_gcash',
            'payment_due_at' => now()->addHour(),
        ]);

        return ManualGcashSubmission::create([
            'payment_id' => $payment->id,
            'booking_id' => $booking->id,
            'attempt_number' => 1,
            'transaction_reference' => 'MONITOR-'.$payment->id,
            'normalized_transaction_reference' => 'MONITOR'.$payment->id,
            'active_reference_claim' => 'MONITOR'.$payment->id,
            'sender_name' => 'Monitoring Guest',
            'submitted_amount' => 500,
            'paid_at' => now()->subMinute(),
            'status' => $status,
            'declaration_accepted_at' => now(),
            'proof_disk' => 'manual_gcash_proofs',
            'proof_path' => 'monitoring/proof.png',
            'proof_original_name' => 'proof.png',
            'proof_mime_type' => 'image/png',
            'proof_size' => 100,
            'proof_sha256' => hash('sha256', 'monitoring-proof'),
            'submitted_at' => now()->subMinutes(20),
            'review_due_at' => now()->subMinutes(5),
            'escalation_due_at' => $escalationDueAt,
        ]);
    }
}
