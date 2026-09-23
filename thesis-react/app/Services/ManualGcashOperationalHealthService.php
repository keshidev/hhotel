<?php

namespace App\Services;

use App\Models\ManualGcashReconciliation;
use App\Models\ManualGcashRefund;
use App\Models\ManualGcashSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManualGcashOperationalHealthService
{
    public function __construct(
        private ManualGcashLaunchReadinessService $launchReadiness,
        private ManualGcashReconciliationAgingService $reconciliationAging
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $readiness = $this->launchReadiness->status();
        $metrics = $this->metrics($readiness);
        $issues = [
            ...$readiness['technical_issues'],
            ...$readiness['approval_issues'],
        ];

        if ($readiness['provider'] !== PaymentProviderService::MANUAL_GCASH || ! $readiness['active']) {
            $issues[] = 'manual_gcash_not_active';
        }

        foreach ([
            'overdue_proof_reviews',
            'stale_unreconciled_payments',
            'stale_reconciliation_exceptions',
            'stale_incomplete_refunds',
            'stale_queue_jobs',
        ] as $metric) {
            if (($metrics[$metric] ?? 0) > 0) {
                $issues[] = $metric;
            }
        }

        $issues = array_values(array_unique($issues));

        return [
            'status' => $issues === [] ? 'ok' : 'degraded',
            'healthy' => $issues === [],
            'environment' => $readiness['environment'],
            'provider' => $readiness['provider'],
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $readiness
     * @return array<string, int|null>
     */
    private function metrics(array $readiness): array
    {
        $reconciliationCutoff = now()->subMinutes(max(
            15,
            (int) config('payment.manual_gcash.monitoring.reconciliation_exception_max_age_minutes', 1440)
        ));
        $refundCutoff = now()->subMinutes(max(
            15,
            (int) config('payment.manual_gcash.monitoring.refund_max_age_minutes', 1440)
        ));
        $queueCutoff = now()->subMinutes(max(
            5,
            (int) config('payment.manual_gcash.monitoring.queue_job_max_age_minutes', 15)
        ));

        return [
            'open_proof_reviews' => (int) ($readiness['open_work']['proof_reviews'] ?? 0),
            'overdue_proof_reviews' => Schema::hasTable('manual_gcash_submissions')
                ? ManualGcashSubmission::query()
                    ->where(function ($query) {
                        $query->where('status', ManualGcashSubmission::STATUS_ESCALATED)
                            ->orWhere(function ($pending) {
                                $pending->where('status', ManualGcashSubmission::STATUS_PENDING)
                                    ->whereNotNull('escalation_due_at')
                                    ->where('escalation_due_at', '<=', now());
                            });
                    })
                    ->count()
                : 0,
            'unreconciled_payments' => Schema::hasTable('manual_gcash_submissions')
                && Schema::hasTable('manual_gcash_reconciliations')
                    ? $this->reconciliationAging->unreconciledCount()
                    : 0,
            'stale_unreconciled_payments' => Schema::hasTable('manual_gcash_submissions')
                && Schema::hasTable('manual_gcash_reconciliations')
                    ? $this->reconciliationAging->overdueCount()
                    : 0,
            'oldest_unreconciled_age_minutes' => Schema::hasTable('manual_gcash_submissions')
                && Schema::hasTable('manual_gcash_reconciliations')
                    ? $this->reconciliationAging->oldestAgeMinutes()
                    : null,
            'open_reconciliation_exceptions' => (int) ($readiness['open_work']['reconciliation_exceptions'] ?? 0),
            'stale_reconciliation_exceptions' => Schema::hasTable('manual_gcash_reconciliations')
                ? ManualGcashReconciliation::query()
                    ->where('status', ManualGcashReconciliation::STATUS_EXCEPTION_OPEN)
                    ->where('created_at', '<=', $reconciliationCutoff)
                    ->count()
                : 0,
            'incomplete_refunds' => (int) ($readiness['open_work']['incomplete_refunds'] ?? 0),
            'stale_incomplete_refunds' => Schema::hasTable('manual_gcash_refunds')
                ? ManualGcashRefund::query()
                    ->where('status', ManualGcashRefund::STATUS_APPROVED)
                    ->where(function ($query) use ($refundCutoff) {
                        $query->where('approved_at', '<=', $refundCutoff)
                            ->orWhere(function ($fallback) use ($refundCutoff) {
                                $fallback->whereNull('approved_at')
                                    ->where('created_at', '<=', $refundCutoff);
                            });
                    })
                    ->count()
                : 0,
            'queued_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'stale_queue_jobs' => Schema::hasTable('jobs')
                ? DB::table('jobs')
                    ->where('created_at', '<=', $queueCutoff->timestamp)
                    ->where('available_at', '<=', now()->timestamp)
                    ->count()
                : 0,
            'failed_jobs' => is_numeric($readiness['failed_jobs']) ? (int) $readiness['failed_jobs'] : null,
            'scheduler_age_seconds' => is_numeric($readiness['scheduler']['age_seconds'] ?? null)
                ? (int) $readiness['scheduler']['age_seconds']
                : null,
        ];
    }
}
