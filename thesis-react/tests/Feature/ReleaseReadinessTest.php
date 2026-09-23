<?php

namespace Tests\Feature;

use App\Services\BackupVerificationService;
use App\Services\ManualGcashOperationalHealthService;
use App\Services\PaymentProviderService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    private string $testDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDirectory = storage_path('framework/testing/release-readiness-'.bin2hex(random_bytes(6)));
        mkdir($this->testDirectory, 0755, true);

        config([
            'release.backup_evidence_path' => $this->testDirectory.'/evidence.json',
            'release.backup_minimum_bytes' => 10,
            'release.backup_artifact_max_age_hours' => 48,
            'release.backup_verification_max_age_hours' => 24,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDirectory)) {
            foreach (glob($this->testDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->testDirectory);
        }

        parent::tearDown();
    }

    public function test_backup_command_records_fresh_checksum_evidence(): void
    {
        $database = $this->testDirectory.'/database.sql';
        $privateFiles = $this->testDirectory.'/private-files.zip';
        file_put_contents($database, str_repeat('CREATE TABLE bookings;', 4));
        file_put_contents($privateFiles, str_repeat('private-file-archive', 4));

        $this->artisan('system:verify-backup', [
            'database' => $database,
            'private-files' => $privateFiles,
            '--restore-test-reference' => 'staging-restore-2026-08-30',
        ])->assertSuccessful();

        $status = app(BackupVerificationService::class)->status();

        $this->assertTrue($status['healthy']);
        $this->assertSame(config('app.env'), $status['evidence']['environment']);
        $this->assertSame(hash_file('sha256', $database), $status['evidence']['artifacts']['database']['sha256']);
        $this->assertArrayNotHasKey('resolved_path', $status['evidence']['artifacts']['database']);
    }

    public function test_backup_command_rejects_missing_restore_reference(): void
    {
        $database = $this->testDirectory.'/database.sql';
        $privateFiles = $this->testDirectory.'/private-files.zip';
        file_put_contents($database, str_repeat('database', 4));
        file_put_contents($privateFiles, str_repeat('private', 4));

        $this->artisan('system:verify-backup', [
            'database' => $database,
            'private-files' => $privateFiles,
        ])->assertFailed();

        $this->assertFileDoesNotExist(config('release.backup_evidence_path'));
    }

    public function test_release_readiness_passes_only_when_all_gates_are_healthy(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://hhotelbooking.com/api',
            'app.frontend_url' => 'https://hhotelbooking.com',
            'app.key' => 'base64:'.str_repeat('a', 44),
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'sanctum.expiration' => 480,
            'security.headers_enabled' => true,
            'security.content_security_policy' => "default-src 'self'",
            'cors.allowed_origins' => ['https://hhotelbooking.com'],
            'queue.default' => 'database',
            'cache.default' => 'database',
            'mail.default' => 'smtp',
            'bookings.captcha.enabled' => true,
            'bookings.captcha.secret' => 'booking-secret',
            'contact.captcha.enabled' => true,
            'contact.captcha.provider' => 'recaptcha',
            'contact.captcha.secret' => 'contact-secret',
        ]);

        $this->mock(BackupVerificationService::class, function ($mock) {
            $mock->shouldReceive('status')->once()->andReturn([
                'healthy' => true,
                'reason' => 'fresh backup and restore evidence is present',
                'evidence' => [],
            ]);
        });
        $this->mock(SchedulerHeartbeatService::class, function ($mock) {
            $mock->shouldReceive('status')->once()->andReturn([
                'healthy' => true,
                'status' => 'ok',
                'age_seconds' => 10,
            ]);
        });
        $this->mock(PaymentProviderService::class, function ($mock) {
            $mock->shouldReceive('operationalIssues')->once()->andReturn([]);
            $mock->shouldReceive('uses')->once()->with(PaymentProviderService::MANUAL_GCASH)->andReturn(true);
        });
        $this->mock(ManualGcashOperationalHealthService::class, function ($mock) {
            $mock->shouldReceive('status')->once()->andReturn([
                'healthy' => true,
                'issues' => [],
            ]);
        });

        $this->artisan('system:release-readiness')
            ->expectsOutputToContain('Staff token lifetime')
            ->expectsOutputToContain('Security headers')
            ->expectsOutputToContain('CORS origins')
            ->expectsOutputToContain('Release readiness passed.')
            ->assertSuccessful();
    }
}
