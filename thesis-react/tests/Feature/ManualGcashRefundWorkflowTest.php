<?php

namespace Tests\Feature;

use App\Mail\BookingWorkflowDecisionMail;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\CancellationApprovalRequest;
use App\Models\ManualGcashRefund;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualGcashRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manual_gcash_refunds');
        Mail::fake();
    }

    public function test_manual_gcash_refund_cannot_complete_without_transfer_evidence(): void
    {
        [$request, $sourcePayment, $admin] = $this->createRefundRequest('NO-EVIDENCE-1');
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/cancellation-requests/{$request->id}/refund", [
            'recipient_name' => 'Refund Guest',
            'recipient_account' => '09171234567',
            'gcash_reference' => 'REFUND-NO-EVIDENCE-1',
            'processed_at' => now()->format('Y-m-d H:i:s'),
            'refund_reason' => 'Approved booking cancellation refund.',
            'manual_transfer_confirmed' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Upload the official GCash refund proof before completing the refund.');

        $this->assertSame(CancellationApprovalRequest::STATUS_REFUND_PENDING, $request->fresh()->status);
        $this->assertSame(Payment::LIFECYCLE_REFUND_REQUIRED, $sourcePayment->fresh()->lifecycle_status);
        $this->assertDatabaseMissing('payments', [
            'booking_id' => $request->booking_id,
            'payment_type' => 'refund',
        ]);
    }

    public function test_admin_can_record_completed_manual_gcash_refund_with_private_proof(): void
    {
        [$request, $sourcePayment, $admin] = $this->createRefundRequest('COMPLETE-1');
        Sanctum::actingAs($admin);

        $response = $this->post("/api/admin/cancellation-requests/{$request->id}/refund", [
            'recipient_name' => 'Refund Guest',
            'recipient_account' => '+63 917 123 4567',
            'gcash_reference' => 'GCASH-REFUND-90001',
            'processed_at' => now()->format('Y-m-d H:i:s'),
            'refund_reason' => 'Approved booking cancellation refund.',
            'manual_transfer_confirmed' => '1',
            'proof' => $this->refundProof(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('request.status', CancellationApprovalRequest::STATUS_REFUNDED)
            ->assertJsonPath('request.manualGcashRefund.status', ManualGcashRefund::STATUS_COMPLETED)
            ->assertJsonPath('request.manualGcashRefund.recipientAccount', '*******4567');

        $refund = ManualGcashRefund::query()->firstOrFail();
        $refundPayment = Payment::query()->where('payment_type', 'refund')->firstOrFail();
        $this->assertSame('09171234567', $refund->recipient_account);
        $this->assertSame('GCASHREFUND90001', $refund->normalized_gcash_reference);
        $this->assertSame($refundPayment->id, $refund->refund_payment_id);
        $this->assertSame('manual_gcash', $refundPayment->provider);
        $this->assertSame(Payment::LIFECYCLE_REFUNDED, $sourcePayment->fresh()->lifecycle_status);
        Storage::disk('manual_gcash_refunds')->assertExists($refund->proof_path);
        Mail::assertQueued(BookingWorkflowDecisionMail::class);

        $this->get("/api/admin/cancellation-requests/{$request->id}/refund-proof")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_duplicate_manual_gcash_refund_reference_is_rejected(): void
    {
        [$firstRequest, , $admin] = $this->createRefundRequest('DUPLICATE-1');
        Sanctum::actingAs($admin);
        $this->submitRefund($firstRequest, 'DUPLICATE-REF-1001')->assertOk();

        [$secondRequest] = $this->createRefundRequest('DUPLICATE-2', $admin);
        $this->submitRefund($secondRequest, 'duplicate ref 1001')
            ->assertConflict()
            ->assertJsonPath('message', 'This GCash refund reference is already recorded.');

        $this->assertSame(CancellationApprovalRequest::STATUS_REFUND_PENDING, $secondRequest->fresh()->status);
        $this->assertDatabaseCount('manual_gcash_refunds', 1);
        $this->assertCount(1, Storage::disk('manual_gcash_refunds')->allFiles());
    }

    protected function submitRefund(CancellationApprovalRequest $request, string $reference)
    {
        return $this->post("/api/admin/cancellation-requests/{$request->id}/refund", [
            'recipient_name' => 'Refund Guest',
            'recipient_account' => '09171234567',
            'gcash_reference' => $reference,
            'processed_at' => now()->format('Y-m-d H:i:s'),
            'refund_reason' => 'Approved booking cancellation refund.',
            'manual_transfer_confirmed' => '1',
            'proof' => $this->refundProof(),
        ], ['Accept' => 'application/json']);
    }

    protected function createRefundRequest(string $suffix, ?User $admin = null): array
    {
        $admin ??= User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $booking = Booking::create([
            'reference_number' => 'BK-'.preg_replace('/[^A-Z0-9]/', '', $suffix),
            'check_in' => now()->addDays(2),
            'check_out' => now()->addDays(3),
            'number_of_guests' => 1,
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'total_amount' => 1000,
            'downpayment_percentage' => 50,
        ]);
        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Refund Guest',
            'email' => strtolower($suffix).'@example.com',
            'phone' => '09171234567',
            'is_primary' => true,
        ]);
        $sourcePayment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'paid_amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'lifecycle_status' => Payment::LIFECYCLE_REFUND_REQUIRED,
            'provider' => 'manual_gcash',
            'provider_reference' => 'PAY-'.$suffix,
            'paid_at' => now()->subHour(),
        ]);
        $request = CancellationApprovalRequest::create([
            'booking_id' => $booking->id,
            'reason' => 'Guest cancellation approved for refund.',
            'refund_amount' => 500,
            'refund_method' => 'manual_gcash',
            'status' => CancellationApprovalRequest::STATUS_REFUND_PENDING,
            'approved_by' => $admin->id,
            'approved_at' => now()->subMinutes(20),
        ]);

        return [$request, $sourcePayment, $admin];
    }

    protected function refundProof(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('gcash-refund.png', $png);
    }
}
