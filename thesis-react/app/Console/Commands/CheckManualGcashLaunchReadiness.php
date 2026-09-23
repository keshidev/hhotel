<?php

namespace App\Console\Commands;

use App\Services\ManualGcashLaunchReadinessService;
use App\Services\PaymentProviderService;
use Illuminate\Console\Command;

class CheckManualGcashLaunchReadiness extends Command
{
    protected $signature = 'payments:manual-gcash-launch-check
        {--require-approval : Fail unless all production launch approvals are recorded}
        {--require-active : Fail unless manual GCash is the active operational provider}';

    protected $description = 'Validate manual GCash production launch and rollback readiness';

    public function handle(ManualGcashLaunchReadinessService $readiness): int
    {
        $status = $readiness->status();

        $this->line("Environment: {$status['environment']}");
        $this->line("Payment provider: {$status['provider']}");
        $this->line("Active staff: {$status['active_admins']} administrator(s), {$status['active_receptionists']} receptionist(s)");
        $this->line('Open manual GCash work: '.collect($status['open_work'])
            ->map(fn (int $count, string $name) => "{$name}={$count}")
            ->implode(', '));

        foreach ($status['warnings'] as $warning) {
            $this->warn("Warning: {$warning}");
        }

        if (! $status['technical_ready']) {
            $this->error('Technical launch issues: '.implode(', ', $status['technical_issues']));

            return self::FAILURE;
        }

        $this->info('Technical launch readiness passed.');

        $isProduction = strtolower(trim((string) $status['environment'])) === 'production';

        if ($isProduction && ! $status['approvals_ready']) {
            $this->warn('Launch approvals pending: '.implode(', ', $status['approval_issues']));

            if ($this->option('require-approval') || $this->option('require-active')) {
                return self::FAILURE;
            }
        } elseif ($isProduction) {
            $this->info('Production launch approvals are complete.');
        } else {
            $this->info('Production approval gates are not required in this environment.');
        }

        if ($this->option('require-active')) {
            if ($status['provider'] !== PaymentProviderService::MANUAL_GCASH || ! $status['active']) {
                $this->error('Manual GCash is not the active operational payment provider.');

                return self::FAILURE;
            }

            $this->info('Manual GCash is active and operational.');
        } elseif ($status['provider'] === PaymentProviderService::DISABLED) {
            $this->warn('Production remains safely disabled. No customer payment flow was activated.');
        }

        return self::SUCCESS;
    }
}
