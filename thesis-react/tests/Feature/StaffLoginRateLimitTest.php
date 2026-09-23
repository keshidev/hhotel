<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StaffLoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_login_throttle_returns_retry_timing_for_the_countdown(): void
    {
        $credentials = [
            'email' => 'unknown-staff@example.com',
            'password' => 'WrongPassword!123',
        ];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/login', $credentials)->assertUnprocessable();
        }

        $response = $this->postJson('/api/login', $credentials)
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message', 'retry_after_seconds'])
            ->assertHeader('Retry-After');

        $this->assertGreaterThan(0, (int) $response->json('retry_after_seconds'));
    }

    public function test_staff_login_page_uses_the_server_retry_time(): void
    {
        $login = file_get_contents(base_path('../react/src/pages/login/Login.jsx'));

        $this->assertStringContainsString('retry_after_seconds', $login);
        $this->assertStringContainsString('staffLoginRetryUntil', $login);
        $this->assertStringContainsString('Try again in', $login);
        $this->assertStringContainsString('storeRetryUntil(nextRetryUntil)', $login);
        $this->assertStringContainsString('disabled={loading || retrySeconds > 0}', $login);
    }

    public function test_login_accepts_case_insensitive_email_and_preserves_password(): void
    {
        $user = User::factory()->create([
            'email' => 'Staff.QA@example.com',
            'password' => 'ValidPassword!123',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => '  STAFF.QA@EXAMPLE.COM  ',
            'password' => 'ValidPassword!123',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/login', [
            'email' => 'staff.qa@example.com',
            'password' => 'validpassword!123',
        ])->assertUnprocessable();
    }

    public function test_login_rejects_malformed_credential_types_without_creating_tokens(): void
    {
        foreach ([
            ['email' => ['staff@example.com'], 'password' => 'ValidPassword!123'],
            ['email' => 'staff@example.com', 'password' => ['ValidPassword!123']],
            ['email' => 'staff@example.com', 'password' => 123456],
            ['email' => '', 'password' => ''],
        ] as $credentials) {
            $this->postJson('/api/login', $credentials)->assertUnprocessable();
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
