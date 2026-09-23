<?php

namespace Tests\Feature;

use App\Http\Controllers\FeedbackController;
use App\Mail\GuestFeedbackRequest;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\CmsSetting;
use App\Models\Feedback;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeedbackIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-30 10:00:00'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expired_feedback_link_cannot_be_viewed_or_submitted(): void
    {
        $feedback = $this->createFeedback($this->createBooking(), [
            'token_expires_at' => now()->subMinute(),
        ]);

        $this->getJson("/api/client/feedback/{$feedback->token}")
            ->assertStatus(410)
            ->assertJsonPath('expired', true);

        $this->postJson("/api/client/feedback/{$feedback->token}", $this->validPayload())
            ->assertStatus(410)
            ->assertJsonPath('expired', true);

        $this->assertFalse($feedback->fresh()->is_submitted);
    }

    public function test_feedback_is_available_only_after_checkout_and_can_be_submitted_once(): void
    {
        $booking = $this->createBooking('confirmed');
        $feedback = $this->createFeedback($booking);

        $this->postJson("/api/client/feedback/{$feedback->token}", $this->validPayload())
            ->assertStatus(409);

        $booking->update(['booking_status' => 'checked_out']);

        $this->postJson("/api/client/feedback/{$feedback->token}", $this->validPayload())
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson("/api/client/feedback/{$feedback->token}", $this->validPayload([
            'review' => 'A replacement review must not overwrite the first one.',
        ]))->assertStatus(409)->assertJsonPath('already_done', true);

        $feedback->refresh();
        $this->assertTrue($feedback->is_submitted);
        $this->assertSame('The room was clean and the staff were helpful.', $feedback->review);
        $this->assertFalse($feedback->is_featured);

        $items = json_decode((string) CmsSetting::where('key', 'testimonials_items')->value('value'), true);
        $staged = collect($items)->firstWhere('feedback_id', $feedback->id);
        $this->assertSame('P. G.', $staged['guest_name']);
        $this->assertFalse($staged['is_active']);
    }

    public function test_public_review_endpoints_return_only_published_reviews_with_masked_names(): void
    {
        $published = $this->createFeedback($this->createBooking(), [
            'is_submitted' => true,
            'is_featured' => true,
            'rating_overall' => 5,
            'review' => 'A published review.',
            'submitted_at' => now(),
        ]);
        $private = $this->createFeedback($this->createBooking(), [
            'is_submitted' => true,
            'is_featured' => false,
            'rating_overall' => 5,
            'review' => 'A private review.',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/client/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.guest_name', 'P. G.');

        $this->assertStringNotContainsString('Prince Gallardo', $response->getContent());
        $this->assertStringNotContainsString((string) $private->review, $response->getContent());
    }

    public function test_public_cms_does_not_expose_pending_testimonials(): void
    {
        CmsSetting::updateOrCreate(
            ['key' => 'testimonials_items'],
            [
                'value' => json_encode([
                    ['id' => 'active', 'review_text' => 'Public review', 'is_active' => true],
                    ['id' => 'pending', 'review_text' => 'Private pending review', 'is_active' => false],
                ]),
                'type' => 'json',
                'group' => 'testimonials',
                'label' => 'Testimonials Items',
            ]
        );

        $response = $this->getJson('/api/client/cms')->assertOk();
        $items = json_decode((string) $response->json('data.testimonials_items'), true);

        $this->assertCount(1, $items);
        $this->assertSame('active', $items[0]['id']);
        $this->assertStringNotContainsString('Private pending review', $response->getContent());
    }

    public function test_admin_publication_uses_canonical_review_and_masked_guest_name(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $feedback = $this->createFeedback($this->createBooking(), [
            'is_submitted' => true,
            'is_featured' => false,
            'rating_overall' => 5,
            'review' => 'The authentic guest review.',
            'submitted_at' => now(),
        ]);

        $tamperedItems = [[
            'id' => 'feedback-'.$feedback->id,
            'guest_name' => 'Full Private Name',
            'review_text' => 'Changed by staff',
            'star_rating' => 1,
            'date' => '2000-01-01',
            'is_active' => true,
            'source' => 'guest_feedback',
            'feedback_id' => $feedback->id,
        ]];
        $revision = $this->getJson('/api/admin/cms')
            ->assertOk()
            ->json('meta.revision');

        $this->putJson('/api/admin/cms', [
            'revision' => $revision,
            'settings' => [[
                'key' => 'testimonials_items',
                'value' => json_encode($tamperedItems),
            ]],
        ])->assertOk();

        $items = json_decode((string) CmsSetting::where('key', 'testimonials_items')->value('value'), true);
        $this->assertSame('P. G.', $items[0]['guest_name']);
        $this->assertSame('The authentic guest review.', $items[0]['review_text']);
        $this->assertSame(5, $items[0]['star_rating']);
        $this->assertTrue($items[0]['is_active']);
        $this->assertTrue($feedback->fresh()->is_featured);
    }

    public function test_feedback_request_is_created_once_with_an_expiring_frontend_link(): void
    {
        config(['app.frontend_url' => 'https://staging.example.test']);
        $booking = $this->createBooking();

        $first = FeedbackController::createForBooking($booking);
        $second = FeedbackController::createForBooking($booking);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame('2026-09-29 10:00:00', $first->fresh()->token_expires_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('feedbacks', 1);
        Mail::assertQueued(GuestFeedbackRequest::class, 1);
    }

    private function createBooking(string $status = 'checked_out'): Booking
    {
        $booking = Booking::create([
            'reference_number' => Booking::generateReferenceNumber(),
            'check_in' => '2026-08-29 15:00:00',
            'check_out' => '2026-08-30 12:00:00',
            'number_of_guests' => 1,
            'booking_status' => $status,
            'reservation_status' => $status === 'checked_out' ? 'completed' : 'confirmed',
            'total_amount' => 1000,
            'booking_source' => 'online',
            'stay_type' => 'overnight',
            'duration_hours' => 21,
        ]);

        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Prince Gallardo',
            'email' => 'guest-'.$booking->id.'@example.com',
            'phone' => '09170000000',
            'is_primary' => true,
        ]);

        return $booking;
    }

    private function createFeedback(Booking $booking, array $overrides = []): Feedback
    {
        return Feedback::create(array_merge([
            'booking_id' => $booking->id,
            'token' => Feedback::generateToken(),
            'token_expires_at' => now()->addDays(30),
            'is_submitted' => false,
            'is_featured' => false,
        ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'rating_cleanliness' => 5,
            'rating_comfort' => 5,
            'rating_staff' => 5,
            'rating_facilities' => 5,
            'rating_overall' => 5,
            'review' => 'The room was clean and the staff were helpful.',
            'has_issue' => false,
            'would_recommend' => true,
        ], $overrides);
    }
}
