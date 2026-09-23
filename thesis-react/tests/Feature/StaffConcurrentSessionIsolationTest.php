<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class StaffConcurrentSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_receptionist_tokens_remain_independent_across_login_order_and_logout(): void
    {
        $password = 'StaffPass#123';

        $admin = User::factory()->create([
            'name' => 'Admin One',
            'email' => 'admin.concurrent@example.com',
            'password' => Hash::make($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $receptionist = User::factory()->create([
            'name' => 'Receptionist One',
            'email' => 'reception.concurrent@example.com',
            'password' => Hash::make($password),
            'role' => 'receptionist',
            'status' => 'active',
        ]);

        $this->assertConcurrentStaffSessions(
            firstEmail: $admin->email,
            secondEmail: $receptionist->email,
            password: $password,
            firstRoleProfileEndpoint: '/api/admin/profile',
            secondRoleProfileEndpoint: '/api/receptionist/profile',
            secondRoleMismatchEndpoint: '/api/admin/profile'
        );

        $this->assertConcurrentStaffSessions(
            firstEmail: $receptionist->email,
            secondEmail: $admin->email,
            password: $password,
            firstRoleProfileEndpoint: '/api/receptionist/profile',
            secondRoleProfileEndpoint: '/api/admin/profile',
            secondRoleMismatchEndpoint: '/api/receptionist/profile'
        );
    }

    private function assertConcurrentStaffSessions(
        string $firstEmail,
        string $secondEmail,
        string $password,
        string $firstRoleProfileEndpoint,
        string $secondRoleProfileEndpoint,
        string $secondRoleMismatchEndpoint
    ): void {
        $firstLogin = $this->postJson('/api/login', [
            'email' => $firstEmail,
            'password' => $password,
        ])->assertOk()->json();

        $secondLogin = $this->postJson('/api/login', [
            'email' => $secondEmail,
            'password' => $password,
        ])->assertOk()->json();

        $this->assertSame($firstEmail, $firstLogin['user']['email'] ?? null);
        $this->assertSame($secondEmail, $secondLogin['user']['email'] ?? null);

        $firstToken = $firstLogin['token'] ?? null;
        $secondToken = $secondLogin['token'] ?? null;

        $this->assertNotEmpty($firstToken);
        $this->assertNotEmpty($secondToken);

        $firstPat = PersonalAccessToken::findToken($firstToken);
        $secondPat = PersonalAccessToken::findToken($secondToken);
        $this->assertNotNull($firstPat);
        $this->assertNotNull($secondPat);
        $this->assertSame($firstEmail, $firstPat->tokenable->email);
        $this->assertSame($secondEmail, $secondPat->tokenable->email);

        $this->getWithToken('/api/me', $firstToken)
            ->assertOk()
            ->assertJsonPath('email', $firstEmail);

        $this->getWithToken('/api/me', $secondToken)
            ->assertOk()
            ->assertJsonPath('email', $secondEmail);

        $this->getWithToken($firstRoleProfileEndpoint, $firstToken)
            ->assertOk();

        $this->getWithToken($secondRoleProfileEndpoint, $secondToken)
            ->assertOk();

        $this->getWithToken($secondRoleMismatchEndpoint, $secondToken)
            ->assertForbidden();

        $this->postWithToken('/api/logout', $firstToken)
            ->assertOk();

        $this->getWithToken($firstRoleProfileEndpoint, $firstToken)
            ->assertUnauthorized();

        $this->getWithToken($secondRoleProfileEndpoint, $secondToken)
            ->assertOk();

        $this->postWithToken('/api/logout', $secondToken)
            ->assertOk();

        $this->getWithToken($secondRoleProfileEndpoint, $secondToken)
            ->assertUnauthorized();
    }

    private function getWithToken(string $uri, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($uri, [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function postWithToken(string $uri, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson($uri, [], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }
}
