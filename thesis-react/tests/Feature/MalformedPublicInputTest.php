<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MalformedPublicInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_special_requests_limit_is_enforced_when_frontend_validation_is_bypassed(): void
    {
        config(['bookings.captcha.enabled' => false]);
        foreach ([null, '', str_repeat('Q', 999), str_repeat('Q', 1000)] as $requests) {
            Cache::flush();
            // Other required fields are deliberately absent to prevent writes.
            $this->postJson('/api/client/bookings', ['special_requests' => $requests])
                ->assertUnprocessable()
                ->assertJsonMissingValidationErrors('special_requests');
        }
        Cache::flush();
        $this->postJson('/api/client/bookings', ['special_requests' => str_repeat('Q', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('special_requests');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_guests', 0);
    }

    public function test_booking_rejects_blank_or_non_string_guest_names_after_normalization(): void
    {
        config(['bookings.captcha.enabled' => false]);
        foreach ([null, '', " \t\r\n ", "\u{00A0}\u{2003}", ['Ana'], 123, false, str_repeat('A', 256)] as $name) {
            Cache::flush();
            $this->postJson('/api/client/bookings', ['guest_name' => $name])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('guest_name');
        }
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_guests', 0);
    }

    public function test_array_identity_fields_return_validation_errors_instead_of_server_errors(): void
    {
        Mail::fake();
        config(['bookings.captcha.enabled' => false]);

        foreach ([
            ['/api/client/bookings', ['guest_email' => ['qa@example.test']], 'guest_email'],
            ['/api/client/bookings', ['guest_phone' => ['09123456789']], 'guest_phone'],
            ['/api/client/forgot-password', ['email' => ['qa@example.test']], 'email'],
            ['/api/client/reset-password', ['email' => ['qa@example.test']], 'email'],
            ['/api/client/contact-inquiries', ['email' => ['qa@example.test']], 'email'],
        ] as [$endpoint, $payload, $field]) {
            Cache::flush();
            $this->postJson($endpoint, $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Mail::assertNothingQueued();
    }

    public function test_booking_accepts_supported_philippine_mobile_formats(): void
    {
        config(['bookings.captcha.enabled' => false]);

        foreach (['9171234567', '09171234567', '639171234567', '+639171234567', '+63 917 123 4567', '(0917) 123-4567'] as $phone) {
            Cache::flush();
            // Other required fields are omitted so no booking is created.
            $this->postJson('/api/client/bookings', ['guest_phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonMissingValidationErrors('guest_phone');
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_rejects_invalid_phone_formats_without_creating_a_booking(): void
    {
        Mail::fake();
        config(['bookings.captcha.enabled' => false]);

        foreach (['123456', '+1234567890123456', '09123abc456', '++447700900123', 9123456789, '+447700900123', '+12025550123', '+638171234567', '+63917123456', '+6391712345678', '091712345678', '+6309171234567'] as $phone) {
            Cache::flush();
            $this->postJson('/api/client/bookings', ['guest_phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('guest_phone');
        }

        $this->assertDatabaseCount('bookings', 0);
        Mail::assertNothingQueued();
    }
}
