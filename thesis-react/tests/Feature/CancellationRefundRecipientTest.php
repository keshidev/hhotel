<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\CancellationApprovalRequest;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\User;
use App\Services\CancellationRefundRecipientService;
use App\Services\GuestBookingAccessSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CancellationRefundRecipientTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        Mail::fake();
        $booking = Booking::create(['reference_number' => 'RECIPIENT-TEST', 'check_in' => now()->addDays(3), 'check_out' => now()->addDays(4), 'number_of_guests' => 1, 'booking_status' => 'confirmed', 'reservation_status' => 'confirmed', 'total_amount' => 1000, 'downpayment_percentage' => 50]);
        BookingGuest::create(['booking_id' => $booking->id, 'name' => 'Booking Guest', 'email' => 'recipient@example.test', 'phone' => '+63 917 123 4567', 'is_primary' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 500, 'payment_type' => 'downpayment', 'payment_method' => 'gcash', 'payment_status' => 'completed', 'provider' => 'manual_gcash', 'paid_at' => now()->subHour()]);
        return $booking;
    }

    private function authorizeGuest(): void
    {
        $this->mock(GuestBookingAccessSessionService::class, fn ($mock) => $mock->shouldReceive('validate')->andReturn(true));
    }

    private function details(): array
    {
        return ['reason' => 'Changed travel plans', 'refund_recipient_name' => '  Actual Payer  ', 'refund_recipient_account' => '+63 918 765 4321', 'refund_recipient_confirmed' => true];
    }

    public function test_guest_confirmation_is_required_and_does_not_create_a_request_when_missing(): void
    {
        $booking = $this->booking();
        $this->authorizeGuest();
        $this->postJson("/api/client/bookings/{$booking->id}/cancel", ['reason' => 'Changed travel plans'])->assertUnprocessable()->assertJsonValidationErrors(['refund_recipient_name', 'refund_recipient_account']);
        $this->postJson("/api/client/bookings/{$booking->id}/cancel", [...$this->details(), 'refund_recipient_confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('refund_recipient_confirmed');
        $this->postJson("/api/client/bookings/{$booking->id}/cancel", [...$this->details(), 'refund_recipient_account' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('refund_recipient_account');
        $this->assertDatabaseCount('cancellation_approval_requests', 0);
        $this->assertSame('confirmed', $booking->fresh()->booking_status);
    }

    public function test_guest_details_are_encrypted_preserved_on_retry_and_only_exposed_in_admin_detail(): void
    {
        $booking = $this->booking();
        $this->authorizeGuest();
        $this->postJson("/api/client/bookings/{$booking->id}/cancel", $this->details())->assertStatus(202);
        $row = CancellationApprovalRequest::firstOrFail();
        $this->assertSame('Actual Payer', $row->refund_recipient_name);
        $this->assertSame('09187654321', $row->refund_recipient_account);
        $this->assertNotNull($row->refund_recipient_confirmed_at);
        $raw = DB::table('cancellation_approval_requests')->find($row->id);
        $this->assertNotSame('Actual Payer', $raw->refund_recipient_name);
        $this->assertNotSame('09187654321', $raw->refund_recipient_account);
        $this->assertArrayNotHasKey('refund_recipient_account', $row->toArray());
        $this->postJson("/api/client/bookings/{$booking->id}/cancel", [...$this->details(), 'refund_recipient_name' => 'Changed Payer'])->assertStatus(202);
        $this->assertSame('Actual Payer', $row->fresh()->refund_recipient_name);
        $this->assertDatabaseCount('cancellation_approval_requests', 1);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->getJson('/api/admin/cancellation-requests')->assertOk()->assertJsonMissingPath('requests.0.refundRecipientSuggestion');
        $this->getJson("/api/admin/cancellation-requests/{$row->id}")->assertOk()
            ->assertJsonPath('refundRecipientSuggestion.name', 'Actual Payer')
            ->assertJsonPath('refundRecipientSuggestion.account', '09187654321')
            ->assertJsonPath('refundRecipientSuggestion.source', 'guest_confirmed');
    }

    public function test_nonrefundable_cancellation_does_not_require_or_save_recipient_details(): void
    {
        $booking = $this->booking();
        $booking->update(['check_in' => now()->startOfDay()]);
        $this->authorizeGuest();
        $this->postJson("/api/client/bookings/{$booking->id}/cancel", ['reason' => 'Changed travel plans'])->assertOk();
        $this->assertNull(CancellationApprovalRequest::firstOrFail()->refund_recipient_confirmed_at);
    }

    public function test_legacy_suggestion_uses_only_approved_sender_and_never_assumes_contact_is_gcash(): void
    {
        $booking = $this->booking();
        $payment = $booking->payments()->firstOrFail();
        $submission = ManualGcashSubmission::create(['payment_id' => $payment->id, 'booking_id' => $booking->id, 'attempt_number' => 1, 'transaction_reference' => '1234567890123', 'normalized_transaction_reference' => '1234567890123', 'sender_name' => 'Rejected Sender', 'submitted_amount' => 500, 'paid_at' => now()->subHour(), 'status' => 'rejected', 'submitted_at' => now(), 'declaration_accepted_at' => now(), 'proof_path' => 'test/proof.png', 'proof_disk' => 'local', 'proof_original_name' => 'proof.png', 'proof_mime_type' => 'image/png', 'proof_size' => 1, 'proof_sha256' => str_repeat('a', 64), 'review_due_at' => now()->addHour(), 'escalation_due_at' => now()->addHours(2)]);
        $row = CancellationApprovalRequest::create(['booking_id' => $booking->id, 'reason' => 'Legacy cancellation', 'refund_amount' => 500, 'status' => 'refund_pending']);
        $service = app(CancellationRefundRecipientService::class);
        $this->assertSame('Booking Guest', $service->adminSuggestion($row)['name']);
        $submission->update(['sender_name' => 'Approved Payer', 'status' => 'approved']);
        $suggestion = $service->adminSuggestion($row->fresh());
        $this->assertSame('Approved Payer', $suggestion['name']);
        $this->assertSame('approved_payment_sender', $suggestion['source']);
        $this->assertSame('', $suggestion['account']);
        $this->assertSame('09171234567', $suggestion['guestContactNumber']);
        $this->assertNull($suggestion['confirmedAt']);
    }

    public function test_no_refundable_money_means_no_recipient_required(): void
    {
        $booking = $this->booking();
        Payment::create(['booking_id' => $booking->id, 'amount' => 500, 'payment_type' => 'refund', 'payment_method' => 'gcash', 'payment_status' => 'refunded', 'provider' => 'manual_gcash']);
        $this->assertFalse(app(CancellationRefundRecipientService::class)->context($booking)['required']);
    }

    public function test_guest_session_and_admin_role_protect_recipient_details(): void
    {
        $booking = $this->booking();
        $this->postJson("/api/client/bookings/{$booking->id}/cancel", $this->details())->assertUnauthorized();
        $row = CancellationApprovalRequest::create(['booking_id' => $booking->id, 'reason' => 'Protected request', 'refund_amount' => 500, 'status' => 'refund_pending']);
        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));
        $this->getJson("/api/admin/cancellation-requests/{$row->id}")->assertForbidden();
    }
}
