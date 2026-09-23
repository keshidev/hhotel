<?php

namespace Tests\Feature;

use App\Mail\BookingWorkflowDecisionMail;
use App\Models\Cancellation;
use App\Models\Payment;
use App\Models\ManualGcashRefund;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class CancellationTwoStepWorkflowTest extends ManualGcashRefundWorkflowTest
{
    public function test_approval_cancels_immediately_and_refund_stays_visible_without_finalize(): void
    {
        [$row, $payment, $admin] = $this->createRefundRequest('TWO-STEPS');
        $row->update(['status' => 'pending_approval', 'approved_at' => null, 'approved_by' => null]);
        $room = \App\Models\Room::create(['room_number' => '901', 'room_type' => 'deluxe', 'capacity' => 2, 'price_per_night' => 1000, 'floor' => 1, 'status' => 'available']);
        \App\Models\BookingRoom::create(['booking_id' => $row->booking_id, 'room_id' => $room->id, 'requested_room_type' => 'deluxe', 'price_per_night' => 1000, 'nights' => 1, 'subtotal' => 1000]);
        $inventory = app(\App\Services\RoomAssignmentService::class);
        $this->assertSame(0, $inventory->countAvailableRoomsByType('deluxe', $row->booking->check_in, $row->booking->check_out));
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/cancellation-requests/{$row->id}/approve")->assertOk()
            ->assertJsonPath('request.bookingStatus', 'cancelled')->assertJsonPath('request.status', 'refund_pending')
            ->assertJsonPath('request.canProcessRefund', true)->assertJsonPath('request.canFinalize', false);
        $this->assertNotNull($row->fresh()->finalized_at);
        $this->assertSame(1, $inventory->countAvailableRoomsByType('deluxe', $row->booking->check_in, $row->booking->check_out));
        $this->assertSame('cancelled', $row->booking->fresh()->booking_status);
        $this->assertSame('pending', Cancellation::firstOrFail()->refund_status);
        $this->assertDatabaseMissing('payments', ['booking_id' => $row->booking_id, 'payment_type' => 'refund']);
        $this->getJson('/api/admin/cancellation-requests?status=refund_pending')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/admin/cancellation-requests/{$row->id}/approve")->assertOk();
        $this->assertDatabaseCount('cancellations', 1);
        Mail::assertQueued(BookingWorkflowDecisionMail::class, fn ($mail) => str_contains($mail->messageBody, 'refund of PHP 500.00 is pending'));
        $this->submitRefund($row, 'REFUND-TWO-STEPS')->assertOk()
            ->assertJsonPath('request.bookingStatus', 'cancelled')->assertJsonPath('request.refundStatusLabel', 'Refund Completed')
            ->assertJsonPath('request.canProcessRefund', false)->assertJsonPath('request.canFinalize', false);
        $this->assertSame('refunded', Cancellation::firstOrFail()->refund_status);
        $this->getJson('/api/admin/cancellation-requests?status=refund_pending')->assertOk()->assertJsonPath('total', 0);
        $this->submitRefund($row, 'REFUND-TWO-STEPS')->assertConflict();
        $this->assertDatabaseCount('manual_gcash_refunds', 1);
        $this->assertSame(1, Payment::where('payment_type', 'refund')->count());
        $this->assertCount(1, Storage::disk('manual_gcash_refunds')->allFiles());
    }

    public function test_no_refund_finishes_at_approval_and_cannot_record_money(): void
    {
        [$row, , $admin] = $this->createRefundRequest('NO-REFUND');
        $row->update(['status' => 'pending_approval', 'refund_amount' => 0]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/cancellation-requests/{$row->id}/approve")->assertOk()
            ->assertJsonPath('request.status', 'cancelled')->assertJsonPath('request.bookingStatus', 'cancelled')
            ->assertJsonPath('request.canProcessRefund', false)->assertJsonPath('request.canFinalize', false);
        $this->assertSame('none', Cancellation::firstOrFail()->refund_status);
        $this->submitRefund($row, 'NO-REFUND')->assertConflict();
        $this->assertDatabaseCount('manual_gcash_refunds', 0);
    }

    public function test_legacy_refunded_request_can_cancel_without_recording_another_refund(): void
    {
        [$row, , $admin] = $this->createRefundRequest('OLD-REFUNDED');
        $row->update(['status' => 'refunded', 'refund_processed_at' => now()]);
        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/cancellation-requests/{$row->id}")->assertOk()->assertJsonPath('canApprove', true)->assertJsonPath('canProcessRefund', false);
        $this->postJson("/api/admin/cancellation-requests/{$row->id}/approve")->assertOk()
            ->assertJsonPath('request.bookingStatus', 'cancelled')->assertJsonPath('request.status', 'refunded');
        $this->assertDatabaseMissing('payments', ['payment_type' => 'refund']);
    }

    public function test_mail_failure_cannot_undo_cancellation_or_delete_committed_refund_proof(): void
    {
        [$row, , $admin] = $this->createRefundRequest('MAIL-FAIL');
        $row->update(['status' => 'pending_approval']);
        Sanctum::actingAs($admin);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->postJson("/api/admin/cancellation-requests/{$row->id}/approve")->assertOk();
        $this->assertSame('cancelled', $row->booking->fresh()->booking_status);
        $this->submitRefund($row, 'MAIL-FAIL-REFUND')->assertOk();
        $refund = ManualGcashRefund::firstOrFail();
        Storage::disk('manual_gcash_refunds')->assertExists($refund->proof_path);
        $this->assertSame('refunded', $row->fresh()->status);
    }

    public function test_checked_in_booking_cannot_be_cancelled_by_approval(): void
    {
        [$row, , $admin] = $this->createRefundRequest('CHECKED-IN');
        $row->update(['status' => 'pending_approval']);
        $row->booking->update(['booking_status' => 'checked_in']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/cancellation-requests/{$row->id}/approve")->assertUnprocessable();
        $this->assertSame('pending_approval', $row->fresh()->status);
        $this->assertDatabaseCount('cancellations', 0);
    }
}
