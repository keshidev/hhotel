<?php

namespace Tests\Feature;

use App\Mail\PasswordChanged;
use App\Mail\PasswordReset;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class StaffPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OldStaffPass#1234';
    private const NEW_PASSWORD = 'NewStaffPass#5678';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://staging.example.com',
            'auth.passwords.users.expire' => 30,
            'auth.passwords.users.throttle' => 60,
        ]);
    }

    public function test_active_staff_receives_an_encrypted_single_use_reset_link(): void
    {
        Mail::fake();
        $user = $this->staff();

        $this->postJson('/api/client/forgot-password', ['email' => strtoupper($user->email)])
            ->assertOk()
            ->assertJsonPath('message', 'If an active staff account uses that email, a reset link has been sent.');

        Mail::assertQueued(PasswordReset::class, function (PasswordReset $mail) use ($user) {
            $this->assertInstanceOf(ShouldBeEncrypted::class, $mail);
            $this->assertStringStartsWith('https://staging.example.com/reset-password?', $mail->resetLink);

            parse_str((string) parse_url($mail->resetLink, PHP_URL_QUERY), $query);
            $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();

            $this->assertNotNull($record);
            $this->assertNotSame($query['token'], $record->token);
            $this->assertTrue(Hash::check($query['token'], $record->token));

            return $mail->hasTo($user->email);
        });

        $this->assertDatabaseHas('activity_logs', [
            'model_id' => $user->id,
            'action_activity' => 'Password Reset Requested',
        ]);
    }

    public function test_unknown_and_inactive_accounts_receive_the_same_generic_response_without_mail(): void
    {
        Mail::fake();
        $inactive = $this->staff(['email' => 'inactive@example.com', 'status' => 'inactive']);

        $unknownResponse = $this->postJson('/api/client/forgot-password', ['email' => 'missing@example.com']);
        $inactiveResponse = $this->postJson('/api/client/forgot-password', ['email' => $inactive->email]);

        $unknownResponse->assertOk();
        $inactiveResponse->assertOk();
        $this->assertSame($unknownResponse->json('message'), $inactiveResponse->json('message'));
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_repeated_request_does_not_issue_another_token_during_the_cooldown(): void
    {
        Mail::fake();
        $user = $this->staff();

        $this->postJson('/api/client/forgot-password', ['email' => $user->email])->assertOk();
        $firstToken = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->postJson('/api/client/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertQueuedCount(1);
        $this->assertSame($firstToken, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
    }

    public function test_password_reset_requires_the_project_strong_password_policy(): void
    {
        $user = $this->staff();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/client/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_successful_reset_changes_password_revokes_sessions_and_sends_confirmation(): void
    {
        Mail::fake();
        $user = $this->staff();
        $user->createToken('browser');
        $user->createToken('mobile');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/client/reset-password', [
            'email' => strtoupper($user->email),
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('token');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Mail::assertQueued(PasswordChanged::class, fn (PasswordChanged $mail) => $mail->hasTo($user->email));

        $audit = ActivityLog::where('action_activity', 'Password Reset Completed')
            ->where('model_id', $user->id)
            ->firstOrFail();

        $this->assertTrue((bool) data_get($audit->new_values, 'password_changed'));
        $this->assertTrue((bool) data_get($audit->new_values, 'existing_sessions_revoked'));
        $this->assertArrayNotHasKey('password', $audit->new_values ?? []);
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        Mail::fake();
        $user = $this->staff();
        $token = Password::broker()->createToken($user);
        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->postJson('/api/client/reset-password', $payload)->assertOk();
        $this->postJson('/api/client/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This password reset link is invalid or expired. Please request a new one.');
    }

    public function test_deactivated_account_cannot_use_a_previously_issued_token(): void
    {
        $user = $this->staff();
        $token = Password::broker()->createToken($user);
        $user->update(['status' => 'inactive']);

        $this->postJson('/api/client/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    private function staff(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Recovery Staff',
            'email' => 'staff@example.com',
            'password' => Hash::make(self::OLD_PASSWORD),
            'role' => 'receptionist',
            'status' => 'active',
        ], $overrides));
    }
}
