<?php

namespace App\Console\Commands;

use App\Services\ManualGcashConfigurationService;
use Illuminate\Console\Command;

class CheckManualGcashConfiguration extends Command
{
    protected $signature = 'payments:manual-gcash-health';

    protected $description = 'Validate the private manual GCash merchant QR configuration';

    public function handle(ManualGcashConfigurationService $manualGcash): int
    {
        $status = $manualGcash->status();

        if (! $status['configured']) {
            $this->error('Manual GCash configuration issues: '.implode(', ', $status['issues']));

            return self::FAILURE;
        }

        $this->info('Manual GCash merchant configuration is ready.');
        $this->info('Customer manual GCash proof workflow is installed.');

        return self::SUCCESS;
    }
}
