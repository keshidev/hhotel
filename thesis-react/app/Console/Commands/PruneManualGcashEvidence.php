<?php

namespace App\Console\Commands;

use App\Services\ManualGcashEvidenceRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PruneManualGcashEvidence extends Command
{
    protected $signature = 'payments:manual-gcash-prune-evidence {--dry-run} {--limit=500}';

    protected $description = 'Securely retire expired manual GCash payment and refund proof files';

    public function handle(ManualGcashEvidenceRetentionService $retention): int
    {
        if (! Schema::hasColumns('manual_gcash_submissions', [
            'proof_retention_expires_at',
            'proof_disposal_started_at',
            'proof_deleted_at',
            'proof_deletion_reason',
        ]) || ! Schema::hasColumns('manual_gcash_refunds', [
            'proof_retention_expires_at',
            'proof_disposal_started_at',
            'proof_deleted_at',
            'proof_deletion_reason',
        ])) {
            $this->error('Manual GCash evidence cleanup requires the Phase 6 retention migration.');

            return self::FAILURE;
        }

        $stats = $retention->prune(
            dryRun: (bool) $this->option('dry-run'),
            limit: (int) $this->option('limit')
        );
        $prefix = $this->option('dry-run') ? 'Dry run' : 'Completed';
        $this->info(sprintf(
            '%s: %d eligible, %d deleted, %d already missing, %d held, %d not due, %d processing, %d errors. Retention: %d days.',
            $prefix,
            $stats['eligible'],
            $stats['deleted'],
            $stats['already_missing'],
            $stats['held'],
            $stats['not_due'],
            $stats['processing'],
            $stats['errors'],
            $retention->retentionDays()
        ));

        return $stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
