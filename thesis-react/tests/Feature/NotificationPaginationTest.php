<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_and_unread_pages_reach_every_owned_record_without_duplicates(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        $ids = []; $unread = [];
        for ($i = 0; $i < 137; $i++) {
            $notification = Notification::create([
                'user_id' => $user->id, 'type' => 'daily_summary', 'title' => "Summary $i", 'message' => 'Pagination fixture',
                'read_at' => $i % 4 === 0 ? now() : null,
            ]);
            // Multiple equal timestamps per page, spanning different dates in Manila time.
            $notification->forceFill(['created_at' => now()->startOfDay()->subDays(intdiv($i, 25))->addHours(9)])->save();
            $ids[] = $notification->id;
            if ($i % 4 !== 0) $unread[] = $notification->id;
        }
        Notification::create(['user_id' => $other->id, 'type' => 'daily_summary', 'title' => 'Private other user', 'message' => 'Not visible']);
        Sanctum::actingAs($user);
        foreach (['all' => $ids, 'unread' => $unread] as $filter => $expected) {
            $cursor = null; $seen = []; $pages = 0;
            do {
                $response = $this->getJson('/api/notifications?'.http_build_query(['limit' => 20, 'filter' => $filter, 'cursor' => $cursor]))
                    ->assertOk()->assertJsonPath('unread_count', 102)->assertJsonPath('total_count', count($expected));
                $page = $response->json('data');
                $this->assertLessThanOrEqual(20, count($page));
                foreach ($page as $row) {
                    $this->assertEquals($user->id, $row['user_id']);
                    if ($filter === 'unread') $this->assertNull($row['read_at']);
                    $this->assertNotContains($row['id'], $seen);
                    $seen[] = $row['id'];
                }
                $cursor = $response->json('next_cursor');
                $this->assertLessThan(12, ++$pages, 'Pagination must finish');
            } while ($cursor);
            $this->assertEqualsCanonicalizing($expected, $seen);
        }
    }

    public function test_read_updates_and_new_arrivals_do_not_break_cursor_progress(): void
    {
        $user = User::factory()->create(['role' => 'receptionist', 'status' => 'active']);
        Sanctum::actingAs($user);
        foreach (range(1, 45) as $i) Notification::create(['user_id' => $user->id, 'type' => 'daily_summary', 'title' => "Summary $i", 'message' => 'fixture']);
        $first = $this->getJson('/api/notifications?filter=unread&limit=20')->assertOk()->json();
        $this->patchJson('/api/notifications/'.$first['data'][0]['id'].'/read')->assertOk();
        Notification::create(['user_id' => $user->id, 'type' => 'daily_summary', 'title' => 'New arrival', 'message' => 'fixture']);
        $second = $this->getJson('/api/notifications?'.http_build_query(['filter' => 'unread', 'cursor' => $first['next_cursor']]))
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total_count', 45)->json();
        $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
        $this->patchJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications?filter=unread')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0)->assertJsonPath('next_cursor', null);
    }

    public function test_invalid_filters_and_cursors_return_validation_errors(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->getJson('/api/notifications?filter=invalid')->assertUnprocessable();
        foreach (['garbage', base64_encode('null'), base64_encode('{"created_at":"invalid-date","id":1}')] as $cursor) {
            $this->getJson('/api/notifications?cursor='.urlencode($cursor))->assertUnprocessable();
        }
    }
}
