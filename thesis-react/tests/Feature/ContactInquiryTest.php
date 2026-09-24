<?php

namespace Tests\Feature;

use App\Jobs\SendContactInquiryNotifications;
use App\Jobs\SendContactInquiryResolution;
use App\Mail\ContactInquiryResolution;
use App\Models\ContactInquiry;
use App\Models\ContactInquiryStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactInquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'contact.captcha.enabled' => false,
            'contact.rate_limit_per_minute' => 20,
            'contact.rate_limit_per_hour' => 50,
        ]);
    }

    public function test_guest_inquiry_is_stored_and_notification_job_is_queued(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/client/contact-inquiries', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.status', 'new');

        $inquiry = ContactInquiry::firstOrFail();
        $this->assertStringStartsWith('INQ', $inquiry->reference_number);
        $this->assertSame('guest@example.com', $inquiry->email);
        $this->assertSame('+639123456789', $inquiry->phone);
        $this->assertNotSame('127.0.0.1', $inquiry->source_ip_hash);

        Queue::assertPushed(SendContactInquiryNotifications::class, function ($job) use ($inquiry) {
            return $job->inquiryId === $inquiry->id;
        });
    }

    public function test_contact_phone_is_optional_and_supported_local_format_is_normalized(): void
    {
        Queue::fake();

        $this->postJson('/api/client/contact-inquiries', $this->validPayload([
            'phone' => '09171234567',
        ]))->assertCreated();

        $this->assertSame('+639171234567', ContactInquiry::firstOrFail()->phone);
    }

    public function test_invalid_contact_phones_are_rejected_without_creating_an_inquiry(): void
    {
        Queue::fake();

        foreach (['123456', '91234567890', '+6391234567890', '+638123456789', '+447700900123', '++639123456789', '9abc123456', 9123456789] as $phone) {
            $this->postJson('/api/client/contact-inquiries', $this->validPayload([
                'phone' => $phone,
            ]))->assertUnprocessable()->assertJsonValidationErrors('phone');
        }

        $this->assertDatabaseCount('contact_inquiries', 0);
        Queue::assertNothingPushed();
    }

    public function test_short_names_and_messages_are_rejected(): void
    {
        Queue::fake();

        foreach ([
            ['first_name' => 'A'],
            ['last_name' => 'B'],
            ['message' => 'Too short'],
        ] as $override) {
            $this->postJson('/api/client/contact-inquiries', $this->validPayload($override))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_key_first($override));
        }

        $this->assertDatabaseCount('contact_inquiries', 0);
        Queue::assertNothingPushed();
    }
    public function test_duplicate_inquiry_returns_existing_reference_without_creating_another_record(): void
    {
        Queue::fake();

        $first = $this->postJson('/api/client/contact-inquiries', $this->validPayload())
            ->assertCreated()
            ->json('data.reference_number');

        $this->postJson('/api/client/contact-inquiries', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.reference_number', $first);

        $this->assertDatabaseCount('contact_inquiries', 1);
        Queue::assertPushed(SendContactInquiryNotifications::class, 1);
    }

    public function test_honeypot_submission_is_rejected_without_storing_an_inquiry(): void
    {
        Queue::fake();

        $this->postJson('/api/client/contact-inquiries', $this->validPayload([
            'website' => 'https://spam.example',
        ]))->assertUnprocessable()->assertJsonValidationErrors('website');

        $this->assertDatabaseCount('contact_inquiries', 0);
        Queue::assertNothingPushed();
    }

    public function test_existing_recaptcha_provider_name_is_supported(): void
    {
        Queue::fake();
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'contact_submit',
                'hostname' => 'staging.hhotelbooking.com',
            ]),
        ]);
        config([
            'contact.captcha.enabled' => true,
            'contact.captcha.provider' => 'recaptcha',
            'contact.captcha.secret' => 'test-secret',
            'contact.captcha.expected_hostnames' => ['staging.hhotelbooking.com'],
        ]);

        $this->postJson('/api/client/contact-inquiries', $this->validPayload([
            'captcha_token' => 'valid-test-token',
        ]))->assertCreated();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
                && $request['secret'] === 'test-secret'
                && $request['response'] === 'valid-test-token';
        });
    }

    public function test_active_staff_can_progress_and_resolve_an_inquiry(): void
    {
        Queue::fake();
        $staff = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]);
        $inquiry = ContactInquiry::create([
            'reference_number' => 'INQTEST0000001',
            'first_name' => 'Guest',
            'last_name' => 'Example',
            'email' => 'guest@example.com',
            'subject' => 'reservation',
            'message' => 'Please help with my reservation details.',
            'submission_key' => hash('sha256', 'staff-status-test'),
        ]);
        Sanctum::actingAs($staff);

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'in_progress',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.assigned_to', $staff->id);

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'resolved',
            'customer_response' => 'We corrected the reservation details and confirmed the updated dates.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_email_status', 'pending');

        $inquiry->refresh();
        $this->assertSame($staff->id, $inquiry->assigned_to);
        $this->assertNotNull($inquiry->resolved_at);
        $this->assertSame('We corrected the reservation details and confirmed the updated dates.', $inquiry->resolution_message);
        $this->assertSame('pending', $inquiry->resolution_email_status);
        $this->assertSame(1, $inquiry->resolution_email_version);
        Queue::assertPushed(SendContactInquiryResolution::class, function ($job) use ($inquiry) {
            return $job->inquiryId === $inquiry->id && $job->resolutionVersion === 1;
        });
        $this->assertDatabaseHas('activity_logs', [
            'model_id' => $inquiry->id,
            'action_activity' => 'Guest Inquiry Status Updated',
        ]);
        $this->assertDatabaseCount('contact_inquiry_status_histories', 2);
    }

    public function test_resolution_email_is_sent_once_and_contains_only_customer_facing_content(): void
    {
        Mail::fake();
        $inquiry = $this->createInquiry('resolution-email');
        $inquiry->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_message' => 'Your billing adjustment has been completed.',
            'resolution_email_status' => 'pending',
            'resolution_email_version' => 1,
        ])->save();

        $job = new SendContactInquiryResolution($inquiry->id, 1);
        $job->handle();
        $job->handle();

        Mail::assertSent(ContactInquiryResolution::class, function (ContactInquiryResolution $mail) use ($inquiry) {
            $html = $mail->render();

            return $mail->hasTo($inquiry->email)
                && str_contains($html, 'Your billing adjustment has been completed.')
                && str_contains($html, $inquiry->reference_number)
                && ! str_contains($html, 'admin@example.com')
                && ! str_contains($html, 'Assigned to');
        });
        Mail::assertSent(ContactInquiryResolution::class, 1);
        $this->assertSame('sent', $inquiry->fresh()->resolution_email_status);
        $this->assertNotNull($inquiry->fresh()->resolution_email_sent_at);
    }

    public function test_blank_customer_response_uses_standard_resolution_email(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $inquiry = $this->createInquiry('standard-resolution');
        $inquiry->forceFill(['status' => 'in_progress', 'assigned_to' => $staff->id])->save();
        Sanctum::actingAs($staff);

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'resolved',
            'customer_response' => '',
        ])->assertOk()
            ->assertJsonPath('data.resolution_message', null)
            ->assertJsonPath('data.resolution_email_status', 'pending');

        Queue::assertPushed(SendContactInquiryResolution::class, 1);
    }

    public function test_customer_resolution_response_is_limited_to_two_thousand_characters(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inquiry = $this->createInquiry('resolution-limit');
        $inquiry->forceFill(['status' => 'in_progress', 'assigned_to' => $staff->id])->save();
        Sanctum::actingAs($staff);

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'resolved',
            'customer_response' => str_repeat('A', 2001),
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_response');

        $this->assertSame('in_progress', $inquiry->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_same_status_and_skipped_workflow_steps_are_blocked(): void
    {
        $staff = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $inquiry = $this->createInquiry('blocked-transition');
        Sanctum::actingAs($staff);

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'new',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This inquiry already has that status.');

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'resolved',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This inquiry status change is not allowed.');

        $this->assertDatabaseCount('contact_inquiry_status_histories', 0);
    }

    public function test_reopening_a_resolved_inquiry_requires_a_reason_and_is_recorded(): void
    {
        $staff = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $inquiry = $this->createInquiry('reopen-reason');
        Sanctum::actingAs($staff);

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'in_progress',
        ])->assertOk();
        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'resolved',
        ])->assertOk();

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'in_progress',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'in_progress',
            'reason' => 'The guest replied with a new question.',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->getJson("/api/contact-inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.status_history')
            ->assertJsonPath('data.status_history.0.reason', 'The guest replied with a new question.');
    }

    public function test_spam_requires_a_reason_and_only_admin_can_restore_it(): void
    {
        $receptionist = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inquiry = $this->createInquiry('spam-restore');
        Sanctum::actingAs($receptionist);

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'spam',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'spam',
            'reason' => 'Repeated advertising with no hotel question.',
        ])->assertOk()->assertJsonPath('data.status', 'spam');

        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'new',
            'reason' => 'Mistaken classification.',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Only an administrator may restore a spam inquiry.');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/contact-inquiries/{$inquiry->id}/status", [
            'status' => 'new',
            'reason' => 'Confirmed this is a legitimate booking inquiry.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.assigned_to', null);

        $this->assertDatabaseHas('contact_inquiry_status_histories', [
            'contact_inquiry_id' => $inquiry->id,
            'from_status' => 'spam',
            'to_status' => 'new',
            'changed_by' => $admin->id,
            'actor_role' => 'admin',
            'reason' => 'Confirmed this is a legitimate booking inquiry.',
        ]);
    }

    public function test_retention_cleanup_removes_status_reasons_with_guest_data(): void
    {
        config(['contact.retention_days' => 30]);
        $inquiry = $this->createInquiry('retention-history');
        $inquiry->forceFill([
            'status' => 'resolved',
            'resolved_at' => now()->subDays(31),
            'resolution_message' => 'We corrected the guest reservation.',
            'resolution_email_error' => 'Previous delivery diagnostic.',
        ])->save();
        $history = ContactInquiryStatusHistory::create([
            'contact_inquiry_id' => $inquiry->id,
            'from_status' => 'in_progress',
            'to_status' => 'resolved',
            'reason' => 'Guest Prince Gallardo confirmed the concern was solved.',
            'actor_role' => 'receptionist',
        ]);

        $this->artisan('contact-inquiries:prune')->assertSuccessful();

        $this->assertNull($inquiry->fresh()->email);
        $this->assertNull($inquiry->fresh()->resolution_message);
        $this->assertNull($inquiry->fresh()->resolution_email_error);
        $this->assertNull($history->fresh()->reason);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Guest',
            'last_name' => 'Example',
            'email' => 'Guest@Example.com',
            'phone' => '+63 912 345 6789',
            'booking_reference' => 'BKTEST123456',
            'subject' => 'reservation',
            'message' => 'Please help me confirm the details of my reservation.',
            'website' => '',
        ], $overrides);
    }

    private function createInquiry(string $key): ContactInquiry
    {
        return ContactInquiry::create([
            'reference_number' => 'INQ' . strtoupper(substr(hash('sha256', $key), 0, 12)),
            'first_name' => 'Guest',
            'last_name' => 'Example',
            'email' => $key . '@example.com',
            'subject' => 'reservation',
            'message' => 'Please help with this booking inquiry.',
            'submission_key' => hash('sha256', $key),
        ]);
    }
}
