<?php

namespace App\Services;

use App\Models\SchedulerHeartbeat;
use Illuminate\Support\Facades\DB;

class SchedulerHeartbeatService
{
    public function record(): SchedulerHeartbeat
    {
        $now = now();

        DB::table('scheduler_heartbeats')->upsert([
            [
                'name' => $this->name(),
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['name'], ['last_seen_at', 'updated_at']);

        return SchedulerHeartbeat::query()
            ->where('name', $this->name())
            ->firstOrFail();
    }

    public function status(): array
    {
        $heartbeat = SchedulerHeartbeat::query()
            ->where('name', $this->name())
            ->first();

        if (!$heartbeat) {
            return [
                'status' => 'missing',
                'healthy' => false,
                'age_seconds' => null,
            ];
        }

        $ageSeconds = max(0, $heartbeat->last_seen_at->diffInSeconds(now()));
        $healthy = $ageSeconds <= $this->maxAgeSeconds();

        return [
            'status' => $healthy ? 'ok' : 'stale',
            'healthy' => $healthy,
            'age_seconds' => $ageSeconds,
        ];
    }

    private function name(): string
    {
        $name = trim((string) config('scheduler_monitoring.heartbeat_name', 'default'));

        return $name !== '' ? mb_substr($name, 0, 100) : 'default';
    }

    private function maxAgeSeconds(): int
    {
        return max(60, min(3600, (int) config('scheduler_monitoring.max_age_seconds', 180)));
    }
}
