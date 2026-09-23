<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmation;
use App\Mail\ManualGcashStatusMail;
use App\Mail\NewBookingAdminNotification;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\ManualGcashConfiguration;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualGcashProofWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('manual_gcash');
        Storage::fake('manual_gcash_proofs');
        Mail::fake();
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable()->change();
        });
        config()->set([
            'app.frontend_url' => 'http://frontend.test',
            'payment.provider' => 'manual_gcash',
            'payment.manual_gcash.implemented' => true,
            'payment.manual_gcash.payment_window_minutes' => 30,
            'payment.manual_gcash.review_target_minutes' => 15,
            'payment.manual_gcash.review_hold_minutes' => 120,
            'payment.manual_gcash.max_submission_attempts' => 3,
        ]);

        $this->configureMerchantQr();
    }

    public function test_booking_creation_hands_off_directly_to_manual_gcash(): void
    {
        config()->set([
            'bookings.captcha.enabled' => false,
        ]);

        $room = Room::create([
            'room_number' => 'DIRECT-HANDOFF-1',
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'available',
            'show_on_website' => true,
            'description' => 'Direct handoff test room',
        ]);
        User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => 'handoff.admin@example.com',
        ]);

        $response = $this->postJson('/api/client/bookings', [
            'guest_name' => 'Dr José O’Neill',
            'guest_email' => 'direct.handoff.test@gmail.com',
            'guest_phone' => '09171234567',
            'guest_country' => 'PH',
            'guest_address_line_1' => '89 Road 1',
            'guest_address_line_2' => null,
            'guest_city' => 'Quezon City',
            'guest_postal_code' => '1105',
            'room_ids' => [$room->id],
            'room_types' => ['deluxe'],
            'check_in' => now()->addDays(4)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
            'number_of_guests' => 1,
            'adults_count' => 1,
            'children_count' => 0,
            'children_ages' => [],
            'payment_method' => 'gcash',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.email_verification_required');

        $bookingId = (int) $response->json('data.booking.id');
        $response->assertJsonPath('data.next_url', "/payment/{$bookingId}");

        $booking = Booking::findOrFail($bookingId);
        $this->assertSame('+639171234567', $booking->primaryGuest->phone);
        $this->assertSame('Dr José O’Neill', $booking->primaryGuest->name);
        $this->assertNotNull($booking->payment_bootstrap_token_hash);
        $this->assertNotNull($booking->payment_bootstrap_expires_at);
        Mail::assertNotQueued(NewBookingAdminNotification::class);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertFalse(Booking::visibleToStaff()->whereKey($bookingId)->exists());

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $this->bootstrapCookieName($bookingId));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/client', $cookie->getPath());
        $this->assertSame('strict', $cookie->getSameSite());

        $this->postJson("/api/client/manual-gcash/{$bookingId}/prepare")
            ->assertUnauthorized();

        $prepareResponse = $this->withCredentials()
            ->withUnencryptedCookie($this->bootstrapCookieName($bookingId), $cookie->getValue())
            ->postJson("/api/client/manual-gcash/{$bookingId}/prepare")
            ->assertOk()
            ->assertJsonPath('data.provider', 'manual_gcash');

        $this->assertNotNull(collect($prepareResponse->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $this->accessCookieName($bookingId)));
        $this->assertNull($booking->fresh()->payment_bootstrap_token_hash);
    }

    public function test_guest_submits_private_exact_amount_proof_with_secure_payment_authorization(): void
    {
        [$booking, $payment, $bootstrapToken] = $this->createManualBooking('PROOF-1');

        $this->postJson("/api/client/manual-gcash/{$booking->id}/prepare")
            ->assertUnauthorized();

        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $paidAt = now()->subMinute()->seconds(0);

        $this->withCredentials()->withUnencryptedCookie($this->accessCookieName($booking->id), $accessToken)
            ->post("/api/client/manual-gcash/{$booking->id}/proof", [
                'transaction_reference' => '1000000000001',
                'sender_name' => 'Secure Guest',
                'amount' => '500.00',
                'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                'declaration_accepted' => '1',
                'proof' => $this->fakePng('proof.png', 40, 40),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.lifecycle_status', Payment::LIFECYCLE_PENDING_VERIFICATION)
            ->assertJsonPath('data.latest_submission.status', ManualGcashSubmission::STATUS_PENDING);

        $submission = ManualGcashSubmission::query()->firstOrFail();
        Storage::disk('manual_gcash_proofs')->assertExists($submission->proof_path);
        Storage::disk('manual_gcash')->assertMissing($submission->proof_path);

        $this->assertSame(1, $payment->fresh()->submission_attempts);
        $this->assertSame(Payment::LIFECYCLE_PENDING_VERIFICATION, $payment->fresh()->lifecycle_status);
        $this->assertTrue($booking->fresh()->expires_at->equalTo($payment->fresh()->review_hold_until));

        $this->getJson("/api/manual-gcash-reviews/{$submission->id}/proof")
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));
        $this->get("/api/manual-gcash-reviews/{$submission->id}/proof")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_booking_creation_replays_the_same_idempotent_request_without_duplicates(): void
    {
        config()->set('bookings.captcha.enabled', false);

        $room = Room::create([
            'room_number' => 'IDEM-REPLAY-1',
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'available',
            'show_on_website' => true,
            'description' => 'Idempotency replay test room',
        ]);
        $payload = $this->bookingCreationPayload($room, 'idempotency.replay@gmail.com');
        $key = '7c08e82a-8cdd-47e9-8df5-79913c3040aa';

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/client/bookings', $payload)
            ->assertCreated();
        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/client/bookings', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.booking.id'), $second->json('data.booking.id'));
        $this->assertSame($first->json('data.booking.reference_number'), $second->json('data.booking.reference_number'));
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_rooms', 1);
        $this->assertDatabaseCount('booking_guests', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_booking_creation_rejects_an_idempotency_key_reused_for_a_different_body(): void
    {
        config()->set('bookings.captcha.enabled', false);

        $room = Room::create([
            'room_number' => 'IDEM-CONFLICT-1',
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'available',
            'show_on_website' => true,
            'description' => 'Idempotency conflict test room',
        ]);
        $payload = $this->bookingCreationPayload($room, 'idempotency.conflict@gmail.com');
        $key = 'd3e272e5-85e5-4e88-a819-90b55bc02136';

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/client/bookings', $payload)
            ->assertCreated();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/client/bookings', [
                ...$payload,
                'guest_name' => 'Different Guest',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Idempotency key reuse with different request data.');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_booking_creation_waits_for_an_in_flight_key_and_allows_retry_after_release(): void
    {
        config()->set('bookings.captcha.enabled', false);
        $room = Room::create([
            'room_number' => 'IDEM-BUSY-1',
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'available',
            'show_on_website' => true,
            'description' => 'In-flight idempotency test room',
        ]);
        $payload = $this->bookingCreationPayload($room, 'idempotency.busy@gmail.com');
        $key = '18ea2fdb-d562-470f-bd65-44b7e063ee8c';
        $lockKey = 'booking_idem:'.hash('sha256', '127.0.0.1|'.$key).':lock';
        $firstRequestLock = \Illuminate\Support\Facades\Cache::lock($lockKey, 60);
        $this->assertTrue($firstRequestLock->get());
        try {
            $this->withHeader('Idempotency-Key', $key)
                ->postJson('/api/client/bookings', $payload)
                ->assertConflict()
                ->assertHeader('Retry-After', '2');
            $this->assertDatabaseCount('bookings', 0);
            $this->assertDatabaseCount('booking_guests', 0);
            $this->assertDatabaseCount('booking_rooms', 0);
            $this->assertDatabaseCount('payments', 0);
        } finally {
            $firstRequestLock->release();
        }
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/client/bookings', $payload)
            ->assertCreated();
        $this->assertDatabaseCount('bookings', 1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestNameWhitespaceCases')]
    public function test_booking_creation_normalizes_guest_name_and_replays_equivalent_whitespace(string $input, string $expected): void
    {
        config()->set('bookings.captcha.enabled', false);
        $room = Room::create([
            'room_number' => 'NAME-SPACING-1',
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'available',
            'show_on_website' => true,
            'description' => 'Guest name normalization test room',
        ]);
        $payload = $this->bookingCreationPayload($room, 'name.spacing@gmail.com');
        $payload['guest_name'] = $input;
        $key = 'eacacda6-a6fa-45c1-9d53-02a0e29005aa';
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/client/bookings', $payload)->assertCreated();
        $this->assertDatabaseHas('booking_guests', [
            'booking_id' => $first->json('data.booking.id'),
            'name' => $expected,
        ]);
        $payload['guest_name'] = $expected;
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/client/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.booking.id', $first->json('data.booking.id'));
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_guests', 1);
    }

    public static function guestNameWhitespaceCases(): array
    {
        return [
            'leading trailing and internal spaces' => ['  Ms   Ana     Santos  ', 'Ms Ana Santos'],
            'tabs linebreaks and compound names' => ["\tMs Ana\t Maria\r\nDe  la Cruz\n", 'Ms Ana Maria De la Cruz'],
            'unicode whitespace and name punctuation' => ["\u{00A0}Dr\u{2003}José\u{00A0} Anne-Marie  O’Neill\u{2003}", 'Dr José Anne-Marie O’Neill'],
            'normalized maximum length' => ['  '.str_repeat('A', 255).'  ', str_repeat('A', 255)],
        ];
    }

    public function test_prepare_rate_limit_is_isolated_from_other_guest_endpoints(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('PREPARE-LIMIT-1');

        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/client/bookings/check-status')->assertUnprocessable();
        }

        $this->withCredentials()
            ->withUnencryptedCookie($this->bootstrapCookieName($booking->id), $bootstrapToken)
            ->postJson("/api/client/manual-gcash/{$booking->id}/prepare")
            ->assertOk()
            ->assertJsonPath('data.provider', 'manual_gcash');
    }

    public function test_proof_requires_exact_13_digit_gcash_reference(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('REFERENCE-FORMAT-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $paidAt = now()->subMinute()->seconds(0)->format('Y-m-d H:i:s');

        foreach (['123456789012', '12345678901234', '12345678901A3'] as $reference) {
            $this->submitProof($booking, $accessToken, $reference, $paidAt)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('transaction_reference');
        }

        $this->assertSame(0, ManualGcashSubmission::query()->count());
        $this->assertSame([], Storage::disk('manual_gcash_proofs')->allFiles());
    }

    public function test_proof_submission_is_not_blocked_by_my_bookings_correction_requests(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('PROOF-LIMIT-ISOLATION-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);

        foreach (range(1, 5) as $attempt) {
            $this->postJson("/api/client/manual-gcash/{$booking->id}/resume")
                ->assertUnauthorized();
        }

        $this->submitProof(
            $booking,
            $accessToken,
            '1000000000002',
            now()->subMinute()->seconds(0)->format('Y-m-d H:i:s')
        )->assertCreated();
    }

    public function test_my_bookings_correction_limiter_returns_a_clear_retry_response(): void
    {
        config()->set('payment.manual_gcash.resume_requests_per_minute', 3);
        [$booking] = $this->createManualBooking('RESUME-LIMIT-1');
        $guestCookie = $this->guestAccessCookie($booking);

        foreach (range(1, 3) as $attempt) {
            $this->withCredentials()->withUnencryptedCookie(
                $guestCookie->getName(),
                $guestCookie->getValue()
            )->postJson("/api/client/manual-gcash/{$booking->id}/resume")
                ->assertUnprocessable();
        }

        $this->withCredentials()->withUnencryptedCookie(
            $guestCookie->getName(),
            $guestCookie->getValue()
        )->postJson("/api/client/manual-gcash/{$booking->id}/resume")
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many payment correction requests. Please wait briefly before trying again.')
            ->assertJsonStructure(['retry_after_seconds']);
    }

    public function test_active_gcash_reference_cannot_be_reused_by_another_booking(): void
    {
        [$firstBooking, , $firstBootstrap] = $this->createManualBooking('DUP-1');
        [$secondBooking, , $secondBootstrap] = $this->createManualBooking('DUP-2');
        $paidAt = now()->subMinute()->seconds(0)->format('Y-m-d H:i:s');

        $firstAccess = $this->prepareAndGetAccessToken($firstBooking, $firstBootstrap);
        $this->submitProof($firstBooking, $firstAccess, '1000000000003', $paidAt)
            ->assertCreated();

        $secondAccess = $this->prepareAndGetAccessToken($secondBooking, $secondBootstrap);
        $this->submitProof($secondBooking, $secondAccess, '1000000000003', $paidAt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction_reference');

        $this->assertSame(1, ManualGcashSubmission::query()->count());
        $this->assertSame(0, $secondBooking->payments()->firstOrFail()->submission_attempts);
    }

    public function test_receptionist_must_match_merchant_record_exactly_before_approval(): void
    {
        [$booking, $payment, $bootstrapToken] = $this->createManualBooking('APPROVE-1');
        $paidAt = now()->subMinute()->seconds(0);
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $this->submitProof($booking, $accessToken, '1000000000004', $paidAt->format('Y-m-d H:i:s'))
            ->assertCreated();

        $submission = ManualGcashSubmission::query()->firstOrFail();
        $receptionist = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        Sanctum::actingAs($receptionist);

        $this->postJson("/api/manual-gcash-reviews/{$submission->id}/approve", [
            'merchant_reference' => '9000000000004',
            'verified_amount' => 500,
            'merchant_paid_at' => $paidAt->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('merchant_record');

        $this->postJson("/api/manual-gcash-reviews/{$submission->id}/approve", [
            'merchant_reference' => '1000000000004',
            'verified_amount' => 500,
            'merchant_paid_at' => $paidAt->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
        ])->assertOk()->assertJsonPath('data.status', ManualGcashSubmission::STATUS_APPROVED);

        $this->assertSame('completed', $payment->fresh()->payment_status);
        $this->assertSame(Payment::LIFECYCLE_PAID, $payment->fresh()->lifecycle_status);
        $this->assertSame('confirmed', $booking->fresh()->booking_status);
        $this->assertSame(0, DB::table('payment_access_sessions')->where('booking_id', $booking->id)->whereNull('revoked_at')->count());
        Mail::assertQueued(BookingConfirmation::class);
        Mail::assertQueued(NewBookingAdminNotification::class);
        $this->assertTrue(Booking::visibleToStaff()->whereKey($booking->id)->exists());
    }

    public function test_general_payment_ledger_cannot_accept_or_reject_manual_gcash_payments(): void
    {
        [$booking, $payment] = $this->createManualBooking('LEDGER-BLOCK-1');
        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));

        $this->postJson("/api/receptionist/payments/{$payment->id}/accept")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Manual GCash payments must be verified through GCash Reviews.');

        $this->postJson("/api/receptionist/payments/{$payment->id}/reject", [
            'reason' => 'Attempted from the legacy payment ledger.',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Manual GCash payments must be verified through GCash Reviews.');

        $this->assertSame('pending', $payment->fresh()->payment_status);
        $this->assertSame(Payment::LIFECYCLE_AWAITING_PAYMENT, $payment->fresh()->lifecycle_status);
        $this->assertSame('pending', $booking->fresh()->booking_status);
        $this->assertSame('pending_payment', $booking->fresh()->reservation_status);
    }

    public function test_rejected_proof_keeps_booking_open_and_issues_one_time_correction_link(): void
    {
        [$booking, $payment, $bootstrapToken] = $this->createManualBooking('RETRY-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $this->submitProof(
            $booking,
            $accessToken,
            '1000000000005',
            now()->subMinute()->seconds(0)->format('Y-m-d H:i:s')
        )->assertCreated();

        $submission = ManualGcashSubmission::query()->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));

        $this->postJson("/api/manual-gcash-reviews/{$submission->id}/reject", [
            'reason' => 'The receipt image does not show a matching merchant transaction.',
        ])->assertOk()
            ->assertJsonPath('message', 'Proof rejected. The guest may submit a correction before the deadline.');

        $this->assertSame('pending', $booking->fresh()->booking_status);
        $this->assertSame('pending', $payment->fresh()->payment_status);
        $this->assertSame(Payment::LIFECYCLE_REJECTED, $payment->fresh()->lifecycle_status);
        $this->assertNotNull($booking->fresh()->payment_bootstrap_token_hash);

        $resumeUrl = null;
        Mail::assertQueued(ManualGcashStatusMail::class, function (ManualGcashStatusMail $mail) use (&$resumeUrl) {
            $resumeUrl = $mail->resumeUrl;

            return $mail->status === 'rejected'
                && $mail->canRetry
                && $mail->attemptsRemaining === 2
                && $mail->paymentDueAt !== null
                && $mail->resumeUrl !== null;
        });

        $this->assertNotNull($resumeUrl);
        $token = basename((string) parse_url($resumeUrl, PHP_URL_PATH));
        $response = $this->get("/api/manual-gcash/resume/{$token}")
            ->assertRedirect(config('app.frontend_url').'/payment/'.$booking->id);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $this->accessCookieName($booking->id));
        $this->assertNotNull($cookie);
        $this->assertNull($booking->fresh()->payment_bootstrap_token_hash);
        $this->assertSame(1, DB::table('payment_access_sessions')->where('booking_id', $booking->id)->whereNull('revoked_at')->count());

        $this->get("/api/manual-gcash/resume/{$token}")
            ->assertRedirect(config('app.frontend_url').'/my-booking?status=payment_link_expired');
    }

    public function test_my_bookings_can_reopen_rejected_proof_with_secure_guest_session(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('RETRY-LOOKUP-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $this->submitProof(
            $booking,
            $accessToken,
            '1000000000006',
            now()->subMinute()->seconds(0)->format('Y-m-d H:i:s')
        )->assertCreated();

        $submission = ManualGcashSubmission::query()->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));
        $this->postJson("/api/manual-gcash-reviews/{$submission->id}/reject", [
            'reason' => 'The uploaded proof cannot be matched to the official merchant record.',
        ])->assertOk();

        $guestCookie = $this->guestAccessCookie($booking);
        $response = $this->withCredentials()->withUnencryptedCookie(
            $guestCookie->getName(),
            $guestCookie->getValue()
        )->postJson("/api/client/manual-gcash/{$booking->id}/resume")
            ->assertOk()
            ->assertJsonPath('booking_id', $booking->id);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $this->accessCookieName($booking->id));
        $this->assertNotNull($cookie);

        $this->withUnencryptedCookie(
            $guestCookie->getName(),
            str_repeat('x', 64)
        )->postJson("/api/client/manual-gcash/{$booking->id}/resume")
            ->assertUnauthorized();
    }

    public function test_rejected_proof_closes_booking_when_payment_window_has_ended(): void
    {
        [$booking, $payment, $bootstrapToken] = $this->createManualBooking('RETRY-CLOSED-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $this->submitProof(
            $booking,
            $accessToken,
            '1000000000007',
            now()->subMinute()->seconds(0)->format('Y-m-d H:i:s')
        )->assertCreated();

        $payment->update(['payment_due_at' => now()->subMinute()]);
        $submission = ManualGcashSubmission::query()->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));

        $this->postJson("/api/manual-gcash-reviews/{$submission->id}/reject", [
            'reason' => 'The proof does not match the merchant record and the deadline has passed.',
        ])->assertOk()->assertJsonPath('message', 'Proof rejected and booking closed.');

        $this->assertSame('cancelled', $booking->fresh()->booking_status);
        $this->assertSame('failed', $payment->fresh()->payment_status);
        Mail::assertQueued(ManualGcashStatusMail::class, fn (ManualGcashStatusMail $mail) =>
            $mail->status === 'rejected'
            && ! $mail->canRetry
            && $mail->resumeUrl === null
        );
    }

    public function test_general_payment_ledger_marks_manual_gcash_as_review_only(): void
    {
        [, $payment] = $this->createManualBooking('LEDGER-VIEW-1');
        Sanctum::actingAs(User::factory()->create(['role' => 'receptionist', 'status' => 'active']));

        $response = $this->getJson('/api/receptionist/payments')->assertOk();
        $record = collect($response->json('payments'))->firstWhere('numeric_id', $payment->id);

        $this->assertNotNull($record);
        $this->assertSame('review_only', $record['allowed_actions']);
    }

    public function test_overdue_proof_is_escalated_without_cancelling_booking(): void
    {
        [$booking, $payment, $bootstrapToken] = $this->createManualBooking('ESCALATE-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $this->submitProof(
            $booking,
            $accessToken,
            '1000000000008',
            now()->subMinute()->seconds(0)->format('Y-m-d H:i:s')
        )->assertCreated();

        $submission = ManualGcashSubmission::query()->firstOrFail();
        $submission->update(['escalation_due_at' => now()->subMinute()]);

        $this->artisan('payments:manual-gcash-escalate')->assertSuccessful();

        $this->assertSame(ManualGcashSubmission::STATUS_ESCALATED, $submission->fresh()->status);
        $this->assertSame(Payment::LIFECYCLE_PAID_UNDER_REVIEW, $payment->fresh()->lifecycle_status);
        $this->assertSame('pending', $booking->fresh()->booking_status);
        $this->assertSame('pending_payment', $booking->fresh()->reservation_status);
    }

    public function test_admin_override_cannot_approve_underpayment_and_reserves_verified_reference(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('ADMIN-1');
        $paidAt = now()->subMinute()->seconds(0);
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $this->submitProof($booking, $accessToken, '1000000000009', $paidAt->format('Y-m-d H:i:s'))
            ->assertCreated();

        $submission = ManualGcashSubmission::query()->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->postJson("/api/manual-gcash-reviews/{$submission->id}/approve", [
            'merchant_reference' => '1000000000010',
            'verified_amount' => 499,
            'merchant_paid_at' => $paidAt->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
            'admin_override' => true,
            'override_reason' => 'Customer entered the reference incorrectly.',
        ])->assertUnprocessable()->assertJsonValidationErrors('verified_amount');

        $this->postJson("/api/manual-gcash-reviews/{$submission->id}/approve", [
            'merchant_reference' => '1000000000010',
            'verified_amount' => 500,
            'merchant_paid_at' => $paidAt->format('Y-m-d H:i:s'),
            'merchant_record_confirmed' => true,
            'admin_override' => true,
            'override_reason' => 'Customer entered the reference incorrectly.',
        ])->assertOk();

        $this->assertSame('1000000000010', $submission->fresh()->active_reference_claim);

        [$secondBooking, , $secondBootstrap] = $this->createManualBooking('ADMIN-2');
        $secondAccess = $this->prepareAndGetAccessToken($secondBooking, $secondBootstrap);
        $this->submitProof($secondBooking, $secondAccess, '1000000000010', $paidAt->format('Y-m-d H:i:s'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction_reference');
    }

    public function test_proof_rejects_payment_time_from_before_the_booking(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('OLD-TIME-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);

        $this->submitProof(
            $booking,
            $accessToken,
            '1000000000011',
            now()->subDay()->format('Y-m-d H:i:s')
        )->assertUnprocessable()->assertJsonValidationErrors('paid_at');

        $this->assertSame(0, ManualGcashSubmission::query()->count());
        $this->assertSame([], Storage::disk('manual_gcash_proofs')->allFiles());
    }

    public function test_repeated_proof_submission_keeps_one_active_submission_and_one_private_file(): void
    {
        [$booking, $payment, $bootstrapToken] = $this->createManualBooking('REPEAT-PROOF-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $paidAt = now()->format('Y-m-d H:i:s');

        $this->submitProof($booking, $accessToken, '1000000000091', $paidAt)
            ->assertCreated();
        $this->submitProof($booking, $accessToken, '1000000000091', $paidAt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof');

        $this->assertSame(1, ManualGcashSubmission::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, Payment::findOrFail($payment->id)->submission_attempts);
        $this->assertCount(1, Storage::disk('manual_gcash_proofs')->allFiles());
    }

    public function test_proof_upload_rejects_unsupported_content_and_oversized_files_without_storage(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('PROOF-FILE-EDGE-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $endpoint = "/api/client/manual-gcash/{$booking->id}/proof";
        $fields = [
            'transaction_reference' => '1000000000092',
            'sender_name' => 'Secure Guest',
            'amount' => '500.00',
            'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'declaration_accepted' => '1',
        ];

        $this->withCredentials()->withUnencryptedCookie($this->accessCookieName($booking->id), $accessToken)
            ->post($endpoint, [...$fields, 'proof' => UploadedFile::fake()->createWithContent('spoofed.png', '<script>not an image</script>')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof');

        $this->withCredentials()->withUnencryptedCookie($this->accessCookieName($booking->id), $accessToken)
            ->post($endpoint, [...$fields, 'proof' => UploadedFile::fake()->create('oversized.png', 5121, 'image/png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof');

        $this->assertDatabaseCount('manual_gcash_submissions', 0);
        $this->assertSame([], Storage::disk('manual_gcash_proofs')->allFiles());
    }

    public function test_invalid_proof_details_leave_no_submission_or_private_file(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('PROOF-DATA-EDGE-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);
        $endpoint = "/api/client/manual-gcash/{$booking->id}/proof";
        $valid = [
            'transaction_reference' => '1000000000093',
            'sender_name' => 'Secure Guest',
            'amount' => '500.00',
            'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'declaration_accepted' => '1',
        ];

        foreach ([
            ['field' => 'declaration_accepted', 'value' => '0'],
            ['field' => 'amount', 'value' => '499.99'],
            ['field' => 'paid_at', 'value' => now()->addHour()->format('Y-m-d H:i:s')],
        ] as $case) {
            $payload = [...$valid, $case['field'] => $case['value'], 'proof' => $this->fakePng('proof.png', 40, 40)];
            $this->withCredentials()->withUnencryptedCookie($this->accessCookieName($booking->id), $accessToken)
                ->post($endpoint, $payload, ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($case['field']);
        }

        $this->assertDatabaseCount('manual_gcash_submissions', 0);
        $this->assertSame([], Storage::disk('manual_gcash_proofs')->allFiles());
    }

    public function test_sender_name_minimum_is_enforced_after_request_whitespace_normalization(): void
    {
        [$booking, , $bootstrapToken] = $this->createManualBooking('SENDER-NAME-EDGE-1');
        $accessToken = $this->prepareAndGetAccessToken($booking, $bootstrapToken);

        $this->withCredentials()->withUnencryptedCookie($this->accessCookieName($booking->id), $accessToken)
            ->post("/api/client/manual-gcash/{$booking->id}/proof", [
                'transaction_reference' => '1000000000094',
                'sender_name' => ' A ',
                'amount' => '500.00',
                'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                'declaration_accepted' => '1',
                'proof' => $this->fakePng('proof.png', 40, 40),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sender_name');

        $this->assertDatabaseCount('manual_gcash_submissions', 0);
        $this->assertSame([], Storage::disk('manual_gcash_proofs')->allFiles());
    }

    private function configureMerchantQr(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $contents = $this->pngContents(400, 400);
        Storage::disk('manual_gcash')->put('official-merchant-qr.png', $contents);

        ManualGcashConfiguration::create([
            'configuration_key' => ManualGcashConfiguration::PRIMARY_KEY,
            'merchant_name' => 'H+ Hotel',
            'account_name' => 'H Plus Hotel Official',
            'account_number' => '09178099482',
            'qr_disk' => 'manual_gcash',
            'qr_path' => 'official-merchant-qr.png',
            'qr_original_name' => 'official-merchant-qr.png',
            'qr_mime_type' => 'image/png',
            'qr_size' => strlen($contents),
            'qr_width' => 400,
            'qr_height' => 400,
            'qr_sha256' => hash('sha256', $contents),
            'configured_at' => now(),
            'configured_by' => $administrator->id,
        ]);
    }

    private function bookingCreationPayload(Room $room, string $email): array
    {
        return [
            'guest_name' => 'Idempotent Guest',
            'guest_email' => $email,
            'guest_phone' => '09171234567',
            'guest_country' => 'PH',
            'guest_address_line_1' => '123 Idempotency Street',
            'guest_address_line_2' => null,
            'guest_city' => 'Manila',
            'guest_postal_code' => '1000',
            'room_ids' => [$room->id],
            'room_types' => ['deluxe'],
            'check_in' => now()->addDays(7)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'number_of_guests' => 1,
            'adults_count' => 1,
            'children_count' => 0,
            'children_ages' => [],
            'payment_method' => 'gcash',
        ];
    }

    private function createManualBooking(string $roomNumber): array
    {
        $bootstrapToken = bin2hex(random_bytes(32));
        $booking = Booking::create([
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'number_of_guests' => 1,
            'booking_status' => 'pending',
            'reservation_status' => 'pending_payment',
            'expires_at' => now()->addMinutes(30),
            'payment_bootstrap_token_hash' => hash('sha256', $bootstrapToken),
            'payment_bootstrap_expires_at' => now()->addMinutes(15),
            'total_amount' => 1000,
            'downpayment_percentage' => 50,
        ]);
        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Secure Guest',
            'email' => 'manual.gcash.'.strtolower($roomNumber).'@gmail.com',
            'phone' => '09170000001',
            'is_primary' => true,
        ]);
        $room = Room::create([
            'room_number' => $roomNumber,
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1000,
            'floor' => 2,
            'status' => 'available',
            'description' => 'Manual GCash test room',
        ]);
        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'price_per_night' => 1000,
            'nights' => 1,
            'subtotal' => 1000,
        ]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 500,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
            'lifecycle_status' => Payment::LIFECYCLE_AWAITING_PAYMENT,
            'provider' => 'manual_gcash',
            'payment_due_at' => now()->addMinutes(30),
        ]);

        return [$booking, $payment, $bootstrapToken];
    }

    private function prepareAndGetAccessToken(Booking $booking, string $bootstrapToken): string
    {
        $response = $this->withCredentials()->withUnencryptedCookie($this->bootstrapCookieName($booking->id), $bootstrapToken)
            ->postJson("/api/client/manual-gcash/{$booking->id}/prepare")
            ->assertOk()
            ->assertJsonPath('data.provider', 'manual_gcash');

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $this->accessCookieName($booking->id));
        $this->assertNotNull($cookie);

        return $cookie->getValue();
    }

    private function submitProof(Booking $booking, string $accessToken, string $reference, string $paidAt)
    {
        return $this->withCredentials()->withUnencryptedCookie($this->accessCookieName($booking->id), $accessToken)
            ->post("/api/client/manual-gcash/{$booking->id}/proof", [
                'transaction_reference' => $reference,
                'sender_name' => 'Secure Guest',
                'amount' => '500.00',
                'paid_at' => $paidAt,
                'declaration_accepted' => '1',
                'proof' => $this->fakePng('proof.png', 40, 40),
            ], ['Accept' => 'application/json']);
    }

    private function bootstrapCookieName(int $bookingId): string
    {
        return (string) config('bookings.payment_bootstrap_cookie_prefix').$bookingId;
    }

    private function accessCookieName(int $bookingId): string
    {
        return (string) config('bookings.payment_access_cookie_prefix').$bookingId;
    }

    private function guestAccessCookie(Booking $booking)
    {
        $response = $this->postJson('/api/client/bookings/check-status', [
            'email' => $booking->primaryGuest()->value('email'),
            'reference_number' => $booking->reference_number,
        ])->assertOk();

        $cookieName = (string) config('bookings.guest_access_cookie_prefix').$booking->id;
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $cookieName);

        $this->assertNotNull($cookie);

        return $cookie;
    }

    private function fakePng(string $filename, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $this->pngContents($width, $height));
    }

    private function pngContents(int $width, int $height): string
    {
        $row = "\x00".str_repeat("\xFF\xFF\xFF", $width);
        $pixels = str_repeat($row, $height);
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', $header)
            .$this->pngChunk('IDAT', gzcompress($pixels, 9))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
