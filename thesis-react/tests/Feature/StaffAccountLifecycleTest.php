<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Mail\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'AdminPass#1234';
    private const STAFF_PASSWORD = 'StaffPass#1234';

    public function test_admin_cannot_modify_another_admin_through_the_api(): void
    {
        $actor = $this->admin('actor@example.com');
        $target = $this->admin('target@example.com');
        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/users/{$target->id}", $this->updatePayload($target, [
            'name' => 'Changed Administrator',
        ]))->assertForbidden();

        $this->assertSame('Admin User', $target->fresh()->name);
    }

    public function test_admin_cannot_change_their_own_role_or_status(): void
    {
        $actor = $this->admin('self@example.com');
        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/users/{$actor->id}", $this->updatePayload($actor, [
            'role' => 'receptionist',
            'current_password' => self::ADMIN_PASSWORD,
        ]))->assertForbidden();

        $this->assertSame('admin', $actor->fresh()->role);
        $this->assertSame('active', $actor->fresh()->status);
    }

    public function test_sensitive_staff_change_requires_the_administrator_password(): void
    {
        $actor = $this->admin('actor@example.com');
        $target = $this->receptionist('staff@example.com');
        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/users/{$target->id}", $this->updatePayload($target, [
            'status' => 'inactive',
        ]))->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_creating_staff_requires_the_administrator_password(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://staging.example.com']);
        $actor = $this->admin('actor@example.com');
        Sanctum::actingAs($actor);
        $payload = [
            'name' => 'María O’Connor-Santos Jr.',
            'email' => 'new.staff@example.com',
            'phone' => '09123456789',
            'role' => 'receptionist',
            'status' => 'active',
        ];

        $this->postJson('/api/admin/users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.com']);

        $this->postJson('/api/admin/users', [
            ...$payload,
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertCreated()->assertJsonPath('invitation_queued', true);

        $this->assertDatabaseHas('users', [
            'name' => 'María O’Connor-Santos Jr.',
            'email' => 'new.staff@example.com',
            'phone' => '09123456789',
        ]);

        $staff = User::where('email', 'new.staff@example.com')->firstOrFail();
        $this->assertFalse(Hash::check(self::STAFF_PASSWORD, $staff->password));
        Mail::assertQueued(StaffInvitation::class, function (StaffInvitation $mail) use ($staff) {
            $this->assertStringStartsWith('https://staging.example.com/reset-password?', $mail->setupLink);
            parse_str((string) parse_url($mail->setupLink, PHP_URL_QUERY), $query);
            $this->assertSame('setup', $query['mode']);
            $record = DB::table('password_reset_tokens')->where('email', $staff->email)->first();
            $this->assertTrue(Hash::check($query['token'], $record->token));
            return $mail->hasTo($staff->email);
        });
    }

    public function test_deactivating_staff_revokes_every_existing_token(): void
    {
        $actor = $this->admin('actor@example.com');
        $target = $this->receptionist('staff@example.com');
        $target->createToken('staff-browser');
        $target->createToken('staff-mobile');
        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/users/{$target->id}", $this->updatePayload($target, [
            'status' => 'inactive',
            'current_password' => self::ADMIN_PASSWORD,
        ]))->assertOk()->assertJsonPath('session_revoked', true);

        $this->assertSame('inactive', $target->fresh()->status);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $target->id,
        ]);
    }

    public function test_invited_staff_sets_a_password_with_the_emailed_single_use_link(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://staging.example.com']);
        Sanctum::actingAs($this->admin('actor@example.com'));

        $this->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'email' => 'new.staff@example.com',
            'role' => 'receptionist',
            'status' => 'active',
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertCreated()->assertJsonPath('invitation_queued', true);

        $mail = null;
        Mail::assertQueued(StaffInvitation::class, function (StaffInvitation $queued) use (&$mail) {
            $mail = $queued;
            return true;
        });
        parse_str((string) parse_url($mail->setupLink, PHP_URL_QUERY), $query);

        $payload = [
            'email' => 'new.staff@example.com',
            'token' => $query['token'],
            'password' => self::STAFF_PASSWORD,
            'password_confirmation' => self::STAFF_PASSWORD,
        ];
        $this->postJson('/api/client/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check(self::STAFF_PASSWORD, User::where('email', 'new.staff@example.com')->firstOrFail()->password));
        $this->postJson('/api/client/reset-password', $payload)->assertUnprocessable();
    }

    public function test_registration_switch_is_not_exposed_as_a_live_setting(): void
    {
        Sanctum::actingAs($this->admin('actor@example.com'));

        $this->getJson('/api/admin/settings')->assertOk()->assertJsonMissingPath('allow_registration');
        $this->putJson('/api/admin/settings', ['allow_registration' => false])
            ->assertOk()->assertJsonMissingPath('settings.allow_registration');
    }

    public function test_admin_cannot_choose_another_staff_members_password(): void
    {
        $actor = $this->admin('actor@example.com');
        $target = $this->receptionist('staff@example.com');
        $target->createToken('staff-browser');
        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/users/{$target->id}", $this->updatePayload($target, [
            'password' => 'NewStaffPass#5678',
            'password_confirmation' => 'NewStaffPass#5678',
            'current_password' => self::ADMIN_PASSWORD,
        ]))->assertForbidden();

        $this->assertTrue(Hash::check(self::STAFF_PASSWORD, $target->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $target->id,
        ]);
    }

    public function test_removing_staff_revokes_access_without_deleting_historical_identity(): void
    {
        $actor = $this->admin('actor@example.com');
        $target = $this->receptionist('staff@example.com');
        $target->createToken('staff-browser');
        Sanctum::actingAs($actor);

        $this->deleteJson("/api/admin/users/{$target->id}", [
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $target->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'model_id' => $target->id,
            'action_activity' => 'User Access Revoked and Deactivated',
        ]);
    }

    public function test_deactivating_an_inactive_account_is_rejected_without_duplicate_audit(): void
    {
        $actor = $this->admin('actor@example.com');
        $target = $this->receptionist('inactive@example.com');
        $target->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);

        $this->deleteJson("/api/admin/users/{$target->id}", [
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertConflict()
            ->assertJsonPath('message', 'This account is already inactive and is retained for audit history.');

        $this->assertSame(0, ActivityLog::where('model_id', $target->id)
            ->where('action_activity', 'User Access Revoked and Deactivated')
            ->count());
    }

    public function test_bulk_deactivation_records_each_original_status_and_revokes_access(): void
    {
        $actor = $this->admin('actor@example.com');
        $first = $this->receptionist('first@example.com');
        $second = $this->receptionist('second@example.com');
        $first->createToken('first-session');
        $second->createToken('second-session');
        Sanctum::actingAs($actor);

        $this->postJson('/api/admin/users/bulk-delete', [
            'ids' => [$first->id, $second->id],
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertOk();

        foreach ([$first, $second] as $staff) {
            $this->assertSame('inactive', $staff->fresh()->status);
            $this->assertDatabaseMissing('personal_access_tokens', [
                'tokenable_type' => User::class,
                'tokenable_id' => $staff->id,
            ]);

            $audit = ActivityLog::where('action_activity', 'User Access Revoked and Deactivated')
                ->where('model_id', $staff->id)
                ->latest('id')
                ->firstOrFail();

            $this->assertSame('active', data_get($audit->old_values, 'status'));
            $this->assertSame('inactive', data_get($audit->new_values, 'status'));
            $this->assertTrue((bool) data_get($audit->new_values, 'access_revoked'));
        }
    }

    public function test_inactive_staff_with_an_existing_token_is_blocked_and_signed_out(): void
    {
        $staff = $this->receptionist('inactive@example.com', 'inactive');
        $plainTextToken = $staff->createToken('old-session')->plainTextToken;

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->assertForbidden()->assertJsonPath('message', 'Your account is inactive. Please contact an administrator.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $staff->id,
        ]);
    }

    public function test_user_listing_uses_bounded_server_pagination_and_global_stats(): void
    {
        $actor = $this->admin('actor@example.com');
        User::factory()->count(3)->create(['role' => 'receptionist', 'status' => 'active']);
        User::factory()->count(2)->create(['role' => 'receptionist', 'status' => 'inactive']);
        Sanctum::actingAs($actor);

        $this->getJson('/api/admin/users?role=receptionist&status=active&per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('stats.total_admins', 1)
            ->assertJsonPath('stats.total_receptionists', 5)
            ->assertJsonPath('stats.active_users', 4);

        $this->getJson('/api/admin/users?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_profile_password_change_uses_strong_policy_and_revokes_every_session(): void
    {
        $staff = $this->receptionist('staff@example.com');
        $staff->createToken('browser');
        $staff->createToken('mobile');
        Sanctum::actingAs($staff);

        $this->postJson('/api/receptionist/profile/change-password', [
            'current_password' => self::STAFF_PASSWORD,
            'new_password' => 'weakpass',
            'new_password_confirmation' => 'weakpass',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password');

        $this->postJson('/api/receptionist/profile/change-password', [
            'current_password' => self::STAFF_PASSWORD,
            'new_password' => self::STAFF_PASSWORD,
            'new_password_confirmation' => self::STAFF_PASSWORD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('new_password')
            ->assertJsonPath('errors.new_password.0', 'New password must be different from your current password.');

        $this->postJson('/api/receptionist/profile/change-password', [
            'current_password' => self::STAFF_PASSWORD,
            'new_password' => 'UpdatedStaff#5678',
            'new_password_confirmation' => 'UpdatedStaff#5678',
        ])->assertOk()
            ->assertJsonPath('session_revoked', true);

        $this->assertTrue(Hash::check('UpdatedStaff#5678', $staff->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $staff->id,
        ]);

        $audit = ActivityLog::where('action_activity', 'Profile Password Changed')
            ->where('model_id', $staff->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue((bool) data_get($audit->new_values, 'password_changed'));
        $this->assertTrue((bool) data_get($audit->new_values, 'existing_sessions_revoked'));
    }

    private function admin(string $email): User
    {
        return User::factory()->create([
            'name' => 'Admin User',
            'email' => $email,
            'password' => Hash::make(self::ADMIN_PASSWORD),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function receptionist(string $email, string $status = 'active'): User
    {
        return User::factory()->create([
            'name' => 'Receptionist User',
            'email' => $email,
            'password' => Hash::make(self::STAFF_PASSWORD),
            'role' => 'receptionist',
            'status' => $status,
        ]);
    }

    private function updatePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'password' => null,
            'password_confirmation' => null,
            'current_password' => null,
        ], $overrides);
    }
}
