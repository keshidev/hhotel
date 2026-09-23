<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_receptionist_only_receive_their_own_notifications(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $receptionist = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);

        foreach (range(1, 25) as $number) {
            $this->createNotification($admin, "Admin alert {$number}");
        }

        $receptionistNotification = $this->createNotification($receptionist, 'Receptionist alert');

        Sanctum::actingAs($admin);

        $this->getJson('/api/notifications?limit=20')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('unread_count', 25)
            ->assertJsonMissing(['id' => $receptionistNotification->id]);

        Sanctum::actingAs($receptionist);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $receptionistNotification->id)
            ->assertJsonPath('unread_count', 1);
    }

    public function test_notification_mutations_are_scoped_to_the_authenticated_staff_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $receptionist = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $adminNotification = $this->createNotification($admin, 'Admin alert');
        $receptionistNotification = $this->createNotification($receptionist, 'Receptionist alert');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/notifications/{$receptionistNotification->id}/read")
            ->assertNotFound();
        $this->deleteJson("/api/notifications/{$receptionistNotification->id}")
            ->assertNotFound();

        $this->patchJson("/api/notifications/{$adminNotification->id}/read")
            ->assertOk()
            ->assertJsonPath('notification.id', $adminNotification->id);

        $this->assertNotNull($adminNotification->fresh()->read_at);
        $this->assertNull($receptionistNotification->fresh()->read_at);

        $this->patchJson('/api/notifications/read-all')->assertOk();

        $this->assertNull($receptionistNotification->fresh()->read_at);
    }

    private function createNotification(User $user, string $title): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => 'booking_created',
            'title' => $title,
            'message' => 'Notification drawer regression test.',
            'data' => ['booking_id' => $user->id],
        ]);
    }
}
