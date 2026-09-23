<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\Rebooking;
use App\Models\RebookingRefund;
use App\Models\Room;
use App\Models\User;
use App\Services\RebookingRequestService;
use App\Services\RebookingAdjustmentService;
use App\Services\PaymentProviderService;
use App\Mail\RebookingAdditionalPaymentRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RebookingPaymentRoomSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->mock(PaymentProviderService::class, function ($mock) {
            $mock->shouldReceive('uses')->andReturnTrue();
            $mock->shouldReceive('operationalIssues')->andReturn([]);
        });
    }

    public function test_room_change_request_requires_a_confirmed_booking(): void
    {
        $currentRoom = $this->createRoom('901', 'superior_queen');
        $requestedRoom = $this->createRoom('902', 'superior_twin');
        [$booking] = $this->createBooking($currentRoom, 'pending', 1000, 500);

        $this->expectExceptionMessage('BOOKING_NOT_CONFIRMED');

        $this->service()->createGuestRequest($booking->id, [
            'new_room_id' => $requestedRoom->id,
        ]);
    }

    public function test_room_change_request_is_blocked_while_payment_is_unsettled(): void
    {
        $currentRoom = $this->createRoom('903', 'superior_queen');
        $requestedRoom = $this->createRoom('904', 'superior_twin');
        [$booking] = $this->createBooking($currentRoom, 'confirmed', 1000, null);
        $this->createPayment($booking, 500, 'pending', Payment::LIFECYCLE_PENDING);

        $this->expectExceptionMessage('BOOKING_PAYMENT_NOT_SETTLED');

        $this->service()->createGuestRequest($booking->id, [
            'new_room_id' => $requestedRoom->id,
        ]);
    }

    public function test_room_change_request_rejects_maintenance_and_cleaning_rooms(): void
    {
        $currentRoom = $this->createRoom('905', 'superior_queen');
        $requestedRoom = $this->createRoom('906', 'superior_twin', 'maintenance');
        [$booking] = $this->createBooking($currentRoom, 'confirmed', 1000, 500);

        try {
            $this->service()->createGuestRequest($booking->id, [
                'new_room_id' => $requestedRoom->id,
            ]);
            $this->fail('Maintenance room should not be requestable.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('REQUESTED_ROOM_NOT_OPERATIONAL', $exception->getMessage());
        }

        $this->assertDatabaseMissing('rebookings', [
            'original_booking_id' => $booking->id,
            'requested_room_id' => $requestedRoom->id,
        ]);
    }

    public function test_upgrade_approval_is_blocked_until_additional_downpayment_is_verified(): void
    {
        $currentRoom = $this->createRoom('907', 'superior_queen');
        $requestedRoom = $this->createRoom('908', 'premier');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 1000, 500);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);

        try {
            $this->service()->approve($rebooking->id, $actor);
            $this->fail('Upgrade should require another verified downpayment.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ADDITIONAL_PAYMENT_REQUIRED:500.00', $exception->getMessage());
        }

        $this->assertSame('1000.00', $booking->fresh()->total_amount);
        $this->assertSame($currentRoom->id, $line->fresh()->room_id);
        $this->assertSame(Rebooking::STATUS_PENDING, $rebooking->fresh()->status);
    }

    public function test_downgrade_approval_is_blocked_when_it_would_create_an_overpayment(): void
    {
        $currentRoom = $this->createRoom('909', 'executive_suite');
        $requestedRoom = $this->createRoom('910', 'superior_queen');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 3000, 3000);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);

        try {
            $this->service()->approve($rebooking->id, $actor);
            $this->fail('Downgrade should require refund review.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('REFUND_REVIEW_REQUIRED:2000.00', $exception->getMessage());
        }

        $this->assertSame('3000.00', $booking->fresh()->total_amount);
        $this->assertSame($currentRoom->id, $line->fresh()->room_id);
        $this->assertSame(Rebooking::STATUS_PENDING, $rebooking->fresh()->status);
    }

    public function test_same_price_room_change_is_approved_and_pending_refund_is_not_counted_as_paid(): void
    {
        $currentRoom = $this->createRoom('911', 'superior_queen');
        $requestedRoom = $this->createRoom('912', 'superior_twin');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 1000, 500);
        $this->createPayment($booking, 400, 'pending', Payment::LIFECYCLE_PENDING, 'refund');
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);

        $approved = $this->service()->approve($rebooking->id, $actor);

        $this->assertSame(Rebooking::STATUS_APPROVED, $approved->status);
        $this->assertSame('1000.00', $booking->fresh()->total_amount);
        $this->assertSame($requestedRoom->id, $line->fresh()->room_id);
        $this->assertTrue((bool) $booking->fresh()->has_been_rebooked);
    }

    public function test_receptionist_can_request_the_exact_additional_payment_and_hold_the_room(): void
    {
        $currentRoom = $this->createRoom('913', 'superior_queen');
        $requestedRoom = $this->createRoom('914', 'premier');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 1000, 500);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);

        $result = app(RebookingAdjustmentService::class)->requestAdditionalPayment($rebooking->id, $actor);

        $payment = $result['payment'];
        $this->assertSame('500.00', $payment->amount);
        $this->assertSame(Payment::PURPOSE_REBOOKING_ADJUSTMENT, $payment->purpose);
        $this->assertSame(Rebooking::STATUS_AWAITING_PAYMENT, $rebooking->fresh()->status);
        $this->assertDatabaseHas('rebooking_room_holds', [
            'rebooking_id' => $rebooking->id,
            'room_id' => $requestedRoom->id,
            'released_at' => null,
        ]);
        Mail::assertQueued(RebookingAdditionalPaymentRequested::class);
    }

    public function test_verified_adjustment_payment_unlocks_final_rebooking_approval(): void
    {
        $currentRoom = $this->createRoom('915', 'superior_queen');
        $requestedRoom = $this->createRoom('916', 'premier');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 1000, 500);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $adjustments = app(RebookingAdjustmentService::class);
        $payment = $adjustments->requestAdditionalPayment($rebooking->id, $actor)['payment'];

        $payment->update([
            'payment_status' => 'completed',
            'lifecycle_status' => Payment::LIFECYCLE_PAID,
            'paid_at' => now(),
            'verified_at' => now(),
            'verified_by' => $actor->id,
        ]);
        $adjustments->markPaymentVerified($payment->fresh());
        $approved = $this->service()->approve($rebooking->id, $actor);

        $this->assertSame(Rebooking::STATUS_APPROVED, $approved->status);
        $this->assertSame('2000.00', $booking->fresh()->total_amount);
        $this->assertSame($requestedRoom->id, $line->fresh()->room_id);
        $this->assertDatabaseMissing('rebooking_room_holds', [
            'rebooking_id' => $rebooking->id,
            'released_at' => null,
        ]);
    }

    public function test_closed_adjustment_payment_keeps_confirmed_booking_unchanged(): void
    {
        $currentRoom = $this->createRoom('917', 'superior_queen');
        $requestedRoom = $this->createRoom('918', 'premier');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 1000, 500);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $adjustments = app(RebookingAdjustmentService::class);
        $payment = $adjustments->requestAdditionalPayment($rebooking->id, $actor)['payment'];

        $payment->update(['payment_status' => 'failed', 'lifecycle_status' => Payment::LIFECYCLE_FAILED]);
        $adjustments->markPaymentClosed($payment->fresh());

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame('1000.00', $booking->fresh()->total_amount);
        $this->assertSame($currentRoom->id, $line->fresh()->room_id);
        $this->assertSame(Rebooking::STATUS_PENDING, $rebooking->fresh()->status);
    }

    public function test_admin_records_refund_evidence_and_finalizes_downgrade(): void
    {
        $currentRoom = $this->createRoom('919', 'executive_suite');
        $requestedRoom = $this->createRoom('920', 'superior_queen');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 3000, 3000);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $adjustments = app(RebookingAdjustmentService::class);
        $adjustments->sendForRefundReview($rebooking->id, $admin);

        $approved = $adjustments->processRefundAndFinalize($rebooking->id, $admin, [
            'recipient_name' => 'Room Change Guest',
            'recipient_account' => '09171234567',
            'gcash_reference' => 'RBKREF123456',
            'processed_at' => now()->toDateTimeString(),
            'refund_reason' => 'Refund for approved lower-priced room change.',
            'manual_transfer_confirmed' => true,
            'proof' => [
                'proof_disk' => 'manual_gcash_refunds',
                'proof_path' => 'tests/rebooking-refund.png',
                'proof_original_name' => 'refund.png',
                'proof_mime_type' => 'image/png',
                'proof_size' => 100,
                'proof_sha256' => str_repeat('a', 64),
            ],
        ]);

        $this->assertSame(Rebooking::STATUS_APPROVED, $approved->status);
        $this->assertSame('1000.00', $booking->fresh()->total_amount);
        $this->assertSame($requestedRoom->id, $line->fresh()->room_id);
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'payment_type' => Payment::TYPE_REFUND,
            'purpose' => Payment::PURPOSE_REBOOKING_ADJUSTMENT,
            'amount' => 2000,
        ]);
        $this->assertDatabaseHas('rebooking_refunds', ['rebooking_id' => $rebooking->id, 'amount' => 2000]);
    }

    public function test_active_rebooking_hold_blocks_the_same_room_for_another_booking(): void
    {
        $firstRoom = $this->createRoom('921', 'superior_queen');
        $secondRoom = $this->createRoom('922', 'superior_queen');
        $requestedRoom = $this->createRoom('923', 'premier');
        [$firstBooking, $firstLine] = $this->createBooking($firstRoom, 'confirmed', 1000, 500);
        [$secondBooking] = $this->createBooking($secondRoom, 'confirmed', 1000, 500);
        $firstRequest = $this->createRebooking($firstBooking, $firstLine, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        app(RebookingAdjustmentService::class)->requestAdditionalPayment($firstRequest->id, $actor);

        try {
            $this->service()->createGuestRequest($secondBooking->id, ['new_room_id' => $requestedRoom->id]);
            $this->fail('A room held for another rebooking must not remain requestable.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('REQUESTED_ROOM_NOT_AVAILABLE', $exception->getMessage());
        }
    }

    public function test_expired_rebooking_payment_can_be_reissued_without_changing_booking(): void
    {
        $currentRoom = $this->createRoom('924', 'superior_queen');
        $requestedRoom = $this->createRoom('925', 'premier');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 1000, 500);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $actor = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $adjustments = app(RebookingAdjustmentService::class);
        $firstPayment = $adjustments->requestAdditionalPayment($rebooking->id, $actor)['payment'];
        $firstPayment->update(['payment_due_at' => now()->subMinute()]);

        $secondPayment = $adjustments->requestAdditionalPayment($rebooking->id, $actor)['payment'];

        $this->assertNotSame($firstPayment->id, $secondPayment->id);
        $this->assertSame('failed', $firstPayment->fresh()->payment_status);
        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame($currentRoom->id, $line->fresh()->room_id);
        $this->assertSame(Rebooking::STATUS_AWAITING_PAYMENT, $rebooking->fresh()->status);
    }

    public static function refundApprovalFailures(): array
    {
        return ['room no longer operational' => [false], 'unexpected write failure' => [true]];
    }

    #[DataProvider('refundApprovalFailures')]
    public function test_recorded_refund_proof_survives_failed_approval_and_approval_can_be_retried(bool $unexpected): void
    {
        Storage::fake('manual_gcash_refunds');
        $currentRoom = $this->createRoom('926', 'executive_suite');
        $requestedRoom = $this->createRoom('927', 'superior_queen');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 3000, 1500);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        app(RebookingAdjustmentService::class)->sendForRefundReview($rebooking->id, $admin);
        Sanctum::actingAs($admin);

        $dispatcher = BookingRoom::getEventDispatcher();
        if ($unexpected) {
            BookingRoom::setEventDispatcher(clone $dispatcher);
            BookingRoom::updating(function () {
                throw new \Exception('Simulated room allocation write failure');
            });
        } else {
            $requestedRoom->update(['status' => 'maintenance']);
        }

        try {
            $this->submitRebookingRefund($rebooking)->assertStatus(202)
                ->assertJsonPath('finalized', false)
                ->assertJsonPath('rebooking.refundRecordedAwaitingApproval', true)
                ->assertJsonPath('rebooking.canProcessRefund', false)
                ->assertJsonPath('rebooking.refund.amount', 500);
        } finally {
            BookingRoom::setEventDispatcher($dispatcher);
        }

        $refund = RebookingRefund::sole();
        Storage::disk('manual_gcash_refunds')->assertExists($refund->proof_path);
        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame('3000.00', $booking->fresh()->total_amount);
        $this->assertSame($currentRoom->id, $line->fresh()->room_id);
        $this->assertSame(Rebooking::FINANCIAL_REFUND_COMPLETED, $rebooking->fresh()->financial_status);
        $this->assertNull($rebooking->fresh()->finalized_at);
        $this->getJson("/api/admin/rebookings/{$rebooking->id}")
            ->assertOk()->assertJsonPath('refundRecordedAwaitingApproval', true)
            ->assertJsonPath('canApprove', true)->assertJsonPath('canProcessRefund', false);
        $this->get("/api/admin/rebookings/{$rebooking->id}/refund-proof")->assertOk();

        // Repeating the refund submission must not duplicate the transfer record
        // or remove the proof attached to the first, committed refund.
        $this->submitRebookingRefund($rebooking)->assertConflict();
        $this->assertDatabaseCount('rebooking_refunds', 1);
        $this->assertSame(1, $booking->payments()->where('payment_type', Payment::TYPE_REFUND)->count());
        Storage::disk('manual_gcash_refunds')->assertExists($refund->proof_path);
        $this->assertCount(1, Storage::disk('manual_gcash_refunds')->allFiles());

        $requestedRoom->update(['status' => 'available']);
        $this->postJson("/api/admin/rebookings/{$rebooking->id}/approve")->assertOk()
            ->assertJsonPath('rebooking.statusKey', Rebooking::STATUS_APPROVED)
            ->assertJsonPath('rebooking.refundRecordedAwaitingApproval', false);
        $this->assertSame('1000.00', $booking->fresh()->total_amount);
        $this->assertSame($requestedRoom->id, $line->fresh()->room_id);
        $this->assertDatabaseCount('rebooking_refunds', 1);
        $this->assertSame(1, $booking->payments()->where('payment_type', Payment::TYPE_REFUND)->count());
        $this->get("/api/admin/rebookings/{$rebooking->id}/refund-proof")->assertOk();
    }

    public function test_refund_rejected_before_commit_cleans_up_only_the_unattached_proof(): void
    {
        Storage::fake('manual_gcash_refunds');
        $currentRoom = $this->createRoom('928', 'executive_suite');
        $requestedRoom = $this->createRoom('929', 'superior_queen');
        [$booking, $line] = $this->createBooking($currentRoom, 'confirmed', 3000, 1500);
        $rebooking = $this->createRebooking($booking, $line, $requestedRoom);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        app(RebookingAdjustmentService::class)->sendForRefundReview($rebooking->id, $admin);
        Sanctum::actingAs($admin);

        $this->submitRebookingRefund($rebooking, ['recipient_account' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('rebooking_refunds', 0);
        $this->assertSame(0, $booking->payments()->where('payment_type', Payment::TYPE_REFUND)->count());
        $this->assertCount(0, Storage::disk('manual_gcash_refunds')->allFiles());
        $this->assertSame(Rebooking::FINANCIAL_REFUND_REVIEW, $rebooking->fresh()->financial_status);
        $this->assertSame($currentRoom->id, $line->fresh()->room_id);
    }

    private function submitRebookingRefund(Rebooking $rebooking, array $overrides = [])
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        return $this->post("/api/admin/rebookings/{$rebooking->id}/refund", array_merge([
            'recipient_name' => 'Room Change Guest',
            'recipient_account' => '09171234567',
            'gcash_reference' => 'RBKRECOVERY123456',
            'processed_at' => now()->toDateTimeString(),
            'refund_reason' => 'Refund for approved lower-priced room change.',
            'manual_transfer_confirmed' => '1',
            'proof' => UploadedFile::fake()->createWithContent('refund.png', $png),
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function service(): RebookingRequestService
    {
        return app(RebookingRequestService::class);
    }

    private function createRoom(string $roomNumber, string $roomType, string $status = 'available'): Room
    {
        $rates = [
            'superior_queen' => 1000,
            'superior_twin' => 1000,
            'premier' => 2000,
            'executive_suite' => 3000,
        ];

        return Room::create([
            'room_number' => $roomNumber,
            'room_type' => $roomType,
            'capacity' => 2,
            'price_per_night' => $rates[$roomType],
            'floor' => 9,
            'status' => $status,
            'show_on_website' => true,
        ]);
    }

    private function createBooking(
        Room $room,
        string $status,
        float $total,
        ?float $completedPayment
    ): array {
        $booking = Booking::create([
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
            'number_of_guests' => 2,
            'booking_status' => $status,
            'reservation_status' => $status === 'confirmed' ? 'confirmed' : 'pending',
            'total_amount' => $total,
            'downpayment_percentage' => 50,
        ]);

        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Room Change Safety Guest',
            'email' => "room-change-{$booking->id}@example.com",
            'phone' => '09170000000',
            'is_primary' => true,
        ]);

        $line = BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'price_per_night' => $total,
            'nights' => 1,
            'subtotal' => $total,
        ]);

        if ($completedPayment !== null) {
            $this->createPayment($booking, $completedPayment, 'completed', Payment::LIFECYCLE_PAID);
        }

        return [$booking, $line];
    }

    private function createPayment(
        Booking $booking,
        float $amount,
        string $status,
        string $lifecycle,
        string $type = 'downpayment'
    ): Payment {
        return Payment::create([
            'booking_id' => $booking->id,
            'amount' => $amount,
            'payment_type' => $type,
            'payment_method' => 'gcash',
            'payment_status' => $status,
            'lifecycle_status' => $lifecycle,
            'provider' => 'manual_gcash',
            'paid_at' => $status === 'completed' ? now() : null,
        ]);
    }

    private function createRebooking(Booking $booking, BookingRoom $line, Room $requestedRoom): Rebooking
    {
        $rebooking = Rebooking::create([
            'original_booking_id' => $booking->id,
            'original_booking_room_id' => $line->id,
            'original_room_id' => $line->room_id,
            'requested_room_id' => $requestedRoom->id,
            'reason' => 'Safety regression test',
            'status' => Rebooking::STATUS_PENDING,
        ]);

        $rebooking->update([
            'rebooking_group_id' => $rebooking->id,
            'is_group_leader' => true,
        ]);

        return $rebooking->fresh();
    }
}
