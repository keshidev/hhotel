<?php

namespace App\Console\Commands;

use App\Services\ReleaseReadinessService;
use Illuminate\Console\Command;

class CheckReleaseReadiness extends Command
{
    protected $signature = 'system:release-readiness
        {--allow-non-production : Allow the environment check to pass outside production}';

    protected $description = 'Run the security, operational, and backup release gates';

    public function handle(ReleaseReadinessService $readiness): int
    {
        $status = $readiness->status(! $this->option('allow-non-production'));

        $this->table(
            ['Result', 'Check', 'Detail'],
            collect($status['checks'])->map(fn (array $check) => [
                $check['passed'] ? 'PASS' : 'FAIL',
                $check['name'],
                $check['detail'],
            ])->all()
        );

        if (! $status['ready']) {
            $this->error('Release readiness failed. Fix every FAIL before deployment.');

            return self::FAILURE;
        }

        $this->info('Release readiness passed.');

        return self::SUCCESS;
    }
}
