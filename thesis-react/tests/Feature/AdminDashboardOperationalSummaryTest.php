<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\CancellationApprovalRequest;
use App\Models\EarlyCheckInRequest;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomTransferRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardOperationalSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_operational_inventory_actions_and_upcoming_arrivals(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        $availableRoom = $this->room('101', 'available');
        $occupiedRoom = $this->room('102', 'occupied');
        $this->room('103', 'cleaning');
        $this->room('104', 'maintenance');

        $workflowBooking = $this->booking('BK-DASH-WORKFLOW', now()->subDay()->toDateString());
        $upcomingBooking = $this->booking('BK-DASH-UPCOMING', today()->toDateString());

        BookingGuest::create([
            'booking_id' => $upcomingBooking->id,
            'name' => 'Dashboard Guest',
            'email' => 'dashboard@example.com',
            'phone' => '09171234567',
            'is_primary' => true,
        ]);
        BookingRoom::create([
            'booking_id' => $upcomingBooking->id,
            'room_id' => $availableRoom->id,
            'price_per_night' => 1500,
            'nights' => 1,
            'subtotal' => 1500,
        ]);

        $payment = Payment::create([
            'booking_id' => $workflowBooking->id,
            'amount' => 500,
            'payment_type' => Payment::TYPE_DOWNPAYMENT,
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
            'provider' => 'manual_gcash',
        ]);

        ManualGcashSubmission::create([
            'payment_id' => $payment->id,
            'booking_id' => $workflowBooking->id,
            'attempt_number' => 1,
            'submission_source' => ManualGcashSubmission::SOURCE_CUSTOMER,
            'transaction_reference' => 'GCASH-DASHBOARD-001',
            'normalized_transaction_reference' => 'GCASHDASHBOARD001',
            'active_reference_claim' => 'GCASHDASHBOARD001',
            'sender_name' => 'Dashboard Guest',
            'submitted_amount' => 500,
            'paid_at' => now(),
            'status' => ManualGcashSubmission::STATUS_ESCALATED,
            'declaration_accepted_at' => now(),
            'proof_disk' => 'manual_gcash_proofs',
            'proof_path' => 'tests/dashboard-proof.jpg',
            'proof_original_name' => 'dashboard-proof.jpg',
            'proof_mime_type' => 'image/jpeg',
            'proof_size' => 1024,
            'proof_sha256' => str_repeat('a', 64),
            'submitted_at' => now(),
            'review_due_at' => now()->addMinutes(15),
            'escalation_due_at' => now()->addMinutes(30),
        ]);

        CancellationApprovalRequest::create([
            'booking_id' => $workflowBooking->id,
            'reason' => 'Test cancellation workflow',
            'status' => CancellationApprovalRequest::STATUS_APPROVED,
        ]);
        RoomTransferRequest::create([
            'booking_id' => $workflowBooking->id,
            'current_room_id' => $occupiedRoom->id,
            'target_room_id' => $availableRoom->id,
            'reason' => 'Test transfer workflow',
            'status' => RoomTransferRequest::STATUS_PENDING_APPROVAL,
        ]);
        EarlyCheckInRequest::create([
            'booking_id' => $workflowBooking->id,
            'reason' => 'Test early arrival workflow',
            'status' => EarlyCheckInRequest::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_rooms', 4)
            ->assertJsonPath('stats.operational_rooms', 3)
            ->assertJsonPath('stats.available_rooms', 1)
            ->assertJsonPath('stats.occupied_rooms', 1)
            ->assertJsonPath('stats.cleaning_rooms', 1)
            ->assertJsonPath('stats.maintenance_rooms', 1)
            ->assertJsonPath('stats.current_occupancy_rate', 33.3)
            ->assertJsonPath('stats.pending_actions', 4)
            ->assertJsonPath('attention.gcash_reviews', 1)
            ->assertJsonPath('attention.cancellations', 1)
            ->assertJsonPath('attention.transfers', 1)
            ->assertJsonPath('attention.early_check_ins', 1)
            ->assertJsonPath('attention.total', 4)
            ->assertJsonPath('upcoming_checkins.0.reference_number', 'BK-DASH-UPCOMING')
            ->assertJsonPath('upcoming_checkins.0.guest_name', 'Dashboard Guest')
            ->assertJsonPath('upcoming_checkins.0.rooms', '101')
            ->assertJsonMissingPath('room_occupancy')
            ->assertJsonMissingPath('recent_bookings');
    }

    private function room(string $number, string $status): Room
    {
        return Room::create([
            'room_number' => $number,
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1500,
            'status' => $status,
        ]);
    }

    private function booking(string $reference, string $checkIn): Booking
    {
        return Booking::create([
            'reference_number' => $reference,
            'check_in' => $checkIn,
            'check_out' => now()->addDays(2)->toDateString(),
            'number_of_guests' => 1,
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'total_amount' => 1500,
            'tax_rate' => 0.12,
            'booking_source' => 'walk_in',
        ]);
    }
}
