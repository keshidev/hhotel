<?php

namespace Tests\Feature;

use App\Services\PaymentProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentProviderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_provider_defaults_to_disabled(): void
    {
        config()->set('payment.provider', null);

        $service = app(PaymentProviderService::class);

        $this->assertSame(PaymentProviderService::DISABLED, $service->provider());
        $this->assertSame(['payment_provider_disabled'], $service->operationalIssues());
    }

    public function test_manual_gcash_is_the_only_operational_provider(): void
    {
        config()->set([
            'payment.provider' => 'manual_gcash',
            'payment.manual_gcash.implemented' => true,
        ]);

        $service = app(PaymentProviderService::class);

        $this->assertSame(PaymentProviderService::MANUAL_GCASH, $service->provider());
    }

    public function test_unknown_provider_is_rejected(): void
    {
        config()->set('payment.provider', 'unknown_gateway');

        $service = app(PaymentProviderService::class);

        $this->assertSame(['payment_provider_invalid'], $service->configurationIssues());
        $this->assertFalse($service->isOperational());
    }

    public function test_retired_gateway_and_verification_routes_are_absent(): void
    {
        $this->postJson('/api/webhooks/paymongo', [])->assertNotFound();
        $this->postJson('/api/client/payments/create-session', [])->assertNotFound();
        $this->getJson('/api/verify-booking/retired-token')->assertNotFound();
        $this->postJson('/api/client/bookings/1/resend-verification', [])->assertNotFound();
    }

    public function test_retired_gateway_and_booking_verification_schema_is_absent(): void
    {
        foreach ([
            'provider_payment_id',
            'checkout_url',
            'qr_url',
            'webhook_payload',
            'provider_operation_status',
            'provider_operation_token',
            'provider_operation_started_at',
            'provider_operation_failed_at',
            'provider_operation_error',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('payments', $column), "payments.{$column} still exists");
        }

        foreach (['email_verification_token', 'email_verification_expires_at', 'email_verified_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('bookings', $column), "bookings.{$column} still exists");
        }

        $this->assertFalse(Schema::hasTable('webhook_events'));
    }
}
