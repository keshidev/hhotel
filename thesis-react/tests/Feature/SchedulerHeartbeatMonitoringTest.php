<?php

namespace Tests\Feature;

use App\Models\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerHeartbeatMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'scheduler_monitoring.heartbeat_name' => 'test-scheduler',
            'scheduler_monitoring.max_age_seconds' => 180,
        ]);
    }

    public function test_heartbeat_command_records_current_scheduler_activity(): void
    {
        $this->artisan('scheduler:heartbeat')
            ->assertSuccessful();

        $heartbeat = SchedulerHeartbeat::query()
            ->where('name', 'test-scheduler')
            ->firstOrFail();

        $this->assertTrue($heartbeat->last_seen_at->isAfter(now()->subSeconds(5)));
    }

    public function test_health_endpoint_reports_missing_heartbeat_without_exposing_details(): void
    {
        $response = $this->getJson('/api/system/scheduler-health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'missing']);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_health_endpoint_and_command_accept_a_current_heartbeat(): void
    {
        SchedulerHeartbeat::create([
            'name' => 'test-scheduler',
            'last_seen_at' => now()->subSeconds(30),
        ]);

        $this->getJson('/api/system/scheduler-health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->artisan('scheduler:health')
            ->assertSuccessful();
    }

    public function test_health_endpoint_and_command_reject_a_stale_heartbeat(): void
    {
        SchedulerHeartbeat::create([
            'name' => 'test-scheduler',
            'last_seen_at' => now()->subSeconds(181),
        ]);

        $this->getJson('/api/system/scheduler-health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'stale']);

        $this->artisan('scheduler:health')
            ->assertFailed();
    }
}
