<?php

namespace App\Console\Commands;

use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'scheduler:heartbeat';
    protected $description = 'Record proof that the Laravel scheduler is running';

    public function handle(SchedulerHeartbeatService $heartbeatService): int
    {
        $heartbeat = $heartbeatService->record();

        $this->info("Scheduler heartbeat recorded at {$heartbeat->last_seen_at->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
