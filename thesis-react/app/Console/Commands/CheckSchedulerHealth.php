<?php

namespace App\Console\Commands;

use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class CheckSchedulerHealth extends Command
{
    protected $signature = 'scheduler:health';
    protected $description = 'Check whether the Laravel scheduler heartbeat is current';

    public function handle(SchedulerHeartbeatService $heartbeatService): int
    {
        $status = $heartbeatService->status();

        if ($status['healthy']) {
            $this->info("Scheduler heartbeat is healthy ({$status['age_seconds']} seconds old).");

            return self::SUCCESS;
        }

        if ($status['status'] === 'missing') {
            $this->error('Scheduler heartbeat is missing.');
        } else {
            $this->error("Scheduler heartbeat is stale ({$status['age_seconds']} seconds old).");
        }

        return self::FAILURE;
    }
}
