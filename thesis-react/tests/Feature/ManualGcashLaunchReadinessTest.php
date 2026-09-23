<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ManualGcashConfigurationService;
use App\Services\ManualGcashLaunchReadinessService;
use App\Services\PaymentProviderService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualGcashLaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://hhotelbooking.com/api',
            'app.frontend_url' => 'https://hhotelbooking.com',
            'payment.provider' => 'disabled',
            'payment.manual_gcash.implemented' => true,
            'queue.default' => 'database',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.encryption' => 'tls',
            'mail.mailers.smtp.username' => 'hotel@example.com',
            'mail.mailers.smtp.password' => 'secret-password',
            'mail.from.address' => 'hotel@example.com',
            'mail.from.name' => 'H+ Hotel',
        ]);

        $manualConfiguration = $this->mock(ManualGcashConfigurationService::class);
        $manualConfiguration->shouldReceive('issues')->zeroOrMoreTimes()->andReturn([]);

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        app(SchedulerHeartbeatService::class)->record();
    }

    public function test_production_manual_gcash_activation_is_blocked_until_every_launch_gate_is_approved(): void
    {
        config()->set('payment.provider', 'manual_gcash');

        $paymentProvider = app(PaymentProviderService::class);

        $this->assertSame([
            'manual_gcash_merchant_verification_not_confirmed',
            'manual_gcash_staff_training_not_confirmed',
            'manual_gcash_backup_not_confirmed',
            'manual_gcash_rollback_not_confirmed',
            'manual_gcash_production_launch_not_approved',
        ], $paymentProvider->configurationIssues());
        $this->assertFalse($paymentProvider->isOperational());

        $this->approveLaunchGates();

        $this->assertSame([], $paymentProvider->configurationIssues());
        $this->assertTrue($paymentProvider->isOperational());
    }

    public function test_launch_command_supports_safe_preflight_and_strict_post_activation_checks(): void
    {
        $status = app(ManualGcashLaunchReadinessService::class)->status();

        $this->assertTrue($status['technical_ready']);
        $this->assertFalse($status['approvals_ready']);
        $this->assertFalse($status['active']);

        $this->artisan('payments:manual-gcash-launch-check')
            ->expectsOutput('Technical launch readiness passed.')
            ->expectsOutput('Production remains safely disabled. No customer payment flow was activated.')
            ->assertSuccessful();

        $this->artisan('payments:manual-gcash-launch-check --require-approval')
            ->assertFailed();

        $this->approveLaunchGates();
        config()->set('payment.provider', 'manual_gcash');

        $this->artisan('payments:manual-gcash-launch-check --require-active')
            ->expectsOutput('Production launch approvals are complete.')
            ->expectsOutput('Manual GCash is active and operational.')
            ->assertSuccessful();
    }

    private function approveLaunchGates(): void
    {
        config()->set([
            'payment.manual_gcash.launch.merchant_verified' => true,
            'payment.manual_gcash.launch.staff_trained' => true,
            'payment.manual_gcash.launch.backup_confirmed' => true,
            'payment.manual_gcash.launch.rollback_reviewed' => true,
            'payment.manual_gcash.launch.production_approved' => true,
        ]);
    }
}
