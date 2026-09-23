<?php

namespace Tests\Feature;

use App\Mail\MailConfigurationTest;
use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailConfigurationDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_masked_effective_mail_configuration_without_secrets(): void
    {
        $admin = $this->actingAsAdmin();
        $this->configureWorkingSmtp();

        SystemSetting::writeMany([
            'smtp_host' => 'stale.example.test',
            'smtp_username' => 'legacy@example.test',
        ]);

        $response = $this->getJson('/api/admin/settings/mail-status')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.mailer', 'smtp')
            ->assertJsonPath('data.host', 'smtp.gmail.com')
            ->assertJsonPath('data.port', 465)
            ->assertJsonPath('data.encryption', 'SSL')
            ->assertJsonPath('data.username', $this->maskedEmail('hotelmailer@gmail.com'))
            ->assertJsonPath('data.from_address', 'no*****@hhotelbooking.com')
            ->assertJsonPath('data.test_recipient', $this->maskedEmail($admin->email));

        $body = $response->getContent();
        $this->assertStringNotContainsString('hotelmailer@gmail.com', $body);
        $this->assertStringNotContainsString('gmail-app-password', $body);

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonMissing(['smtp_host' => 'stale.example.test'])
            ->assertJsonMissing(['smtp_username' => 'legacy@example.test']);
    }

    public function test_admin_can_send_audited_test_email_only_to_their_account(): void
    {
        Mail::fake();
        $admin = $this->actingAsAdmin();
        $this->configureWorkingSmtp();

        $this->postJson('/api/admin/settings/send-test-email')
            ->assertOk()
            ->assertJsonPath('message', 'Test email sent to ' . $this->maskedEmail($admin->email) . '.');

        Mail::assertSent(MailConfigurationTest::class, function (MailConfigurationTest $mail) use ($admin) {
            return $mail->hasTo($admin->email);
        });

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action_activity' => 'Email Delivery Test Sent',
            'action' => 'tested',
        ]);
    }

    public function test_test_email_is_blocked_when_mail_configuration_is_incomplete(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        config()->set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '',
            'mail.mailers.smtp.port' => 0,
            'mail.mailers.smtp.username' => '',
            'mail.mailers.smtp.password' => '',
            'mail.from.address' => 'invalid',
        ]);

        $this->postJson('/api/admin/settings/send-test-email')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Email delivery is not fully configured. Resolve the listed configuration issues first.')
            ->assertJsonCount(5, 'issues');

        Mail::assertNothingSent();
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_receptionist_cannot_access_mail_diagnostics_or_send_tests(): void
    {
        $receptionist = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]);
        Sanctum::actingAs($receptionist);

        $this->getJson('/api/admin/settings/mail-status')->assertForbidden();
        $this->postJson('/api/admin/settings/send-test-email')->assertForbidden();
    }

    public function test_legacy_smtp_settings_are_removed_and_cannot_be_saved_again(): void
    {
        $this->actingAsAdmin();

        $this->assertSame(0, DB::table('system_settings')->whereIn('key', [
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
        ])->count());

        $this->putJson('/api/admin/settings', [
            'hotel_name' => 'H+ Hotel',
            'smtp_host' => 'ignored.example.test',
            'smtp_port' => '99999',
            'smtp_encryption' => 'invalid',
            'smtp_username' => 'ignored@example.test',
        ])->assertOk()
            ->assertJsonMissing(['smtp_host' => 'ignored.example.test']);

        $this->assertSame(0, DB::table('system_settings')->whereIn('key', [
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
        ])->count());
        $this->assertSame('H+ Hotel', SystemSetting::read('hotel_name'));
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function configureWorkingSmtp(): void
    {
        config()->set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.encryption' => 'ssl',
            'mail.mailers.smtp.username' => 'hotelmailer@gmail.com',
            'mail.mailers.smtp.password' => 'gmail-app-password',
            'mail.from.address' => 'noreply@hhotelbooking.com',
            'mail.from.name' => 'H+ Hotel',
            'queue.default' => 'database',
        ]);
    }

    private function maskedEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible . str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
    }
}
