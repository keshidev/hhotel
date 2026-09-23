<?php

namespace App\Console\Commands;

use App\Services\ManualGcashOperationalHealthService;
use Illuminate\Console\Command;

class CheckManualGcashOperationalHealth extends Command
{
    protected $signature = 'payments:manual-gcash-operations-health';

    protected $description = 'Check active manual GCash operations for stalled reviews, exceptions, refunds, or queue work';

    public function handle(ManualGcashOperationalHealthService $health): int
    {
        $status = $health->status();

        $this->line("Environment: {$status['environment']}");
        $this->line("Payment provider: {$status['provider']}");
        $this->line('Operational metrics: '.collect($status['metrics'])
            ->map(fn ($value, string $name) => $name.'='.($value ?? 'unavailable'))
            ->implode(', '));

        if (! $status['healthy']) {
            $this->error('Manual GCash operational issues: '.implode(', ', $status['issues']));

            return self::FAILURE;
        }

        $this->info('Manual GCash operations are healthy.');

        return self::SUCCESS;
    }
}
