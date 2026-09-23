<?php

namespace App\Services;

use App\Models\ManualGcashReconciliation;
use App\Models\ManualGcashRefund;
use App\Models\ManualGcashSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManualGcashLaunchReadinessService
{
    public function __construct(
        private PaymentProviderService $paymentProvider,
        private ManualGcashConfigurationService $manualGcashConfiguration,
        private MailConfigurationService $mailConfiguration,
        private SchedulerHeartbeatService $schedulerHeartbeat
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $technicalIssues = [];
        $warnings = [];
        $provider = $this->paymentProvider->provider();

        if (! in_array($provider, [PaymentProviderService::DISABLED, PaymentProviderService::MANUAL_GCASH], true)) {
            $technicalIssues[] = 'manual_gcash_launch_requires_disabled_or_manual_gcash_provider';
        }

        if ($this->isProduction() && (bool) config('app.debug', false)) {
            $technicalIssues[] = 'production_debug_must_be_disabled';
        }

        if ($this->isProduction()) {
            if (! $this->isHttpsUrl((string) config('app.url', ''))) {
                $technicalIssues[] = 'production_app_url_must_use_https';
            }
            if (! $this->isHttpsUrl((string) config('app.frontend_url', ''))) {
                $technicalIssues[] = 'production_frontend_url_must_use_https';
            }
        }

        $technicalIssues = [...$technicalIssues, ...$this->schemaIssues()];

        if (Schema::hasTable('manual_gcash_configurations')) {
            $technicalIssues = [...$technicalIssues, ...$this->manualGcashConfiguration->issues()];
        }

        if ((string) config('queue.default') !== 'database') {
            $technicalIssues[] = 'database_queue_required';
        }

        $mailStatus = $this->mailConfiguration->status();
        if (! $mailStatus['configured']) {
            $technicalIssues[] = 'mail_delivery_not_configured';
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = DB::table('failed_jobs')->count();
            if ($failedJobs > 0) {
                $technicalIssues[] = 'failed_queue_jobs_require_resolution';
            }
        } else {
            $failedJobs = null;
        }

        if (Schema::hasTable('scheduler_heartbeats')) {
            $schedulerStatus = $this->schedulerHeartbeat->status();
            if (! $schedulerStatus['healthy']) {
                $technicalIssues[] = 'scheduler_heartbeat_not_healthy';
            }
        } else {
            $schedulerStatus = ['status' => 'missing', 'healthy' => false, 'age_seconds' => null];
        }

        if (Schema::hasTable('users')) {
            $activeAdmins = User::query()->active()->admins()->count();
            $activeReceptionists = User::query()->active()->receptionists()->count();
            if ($activeAdmins < 1) {
                $technicalIssues[] = 'active_administrator_required';
            }
            if ($activeReceptionists < 1) {
                $technicalIssues[] = 'active_receptionist_required';
            }
        } else {
            $activeAdmins = 0;
            $activeReceptionists = 0;
        }

        $technicalIssues = [...$technicalIssues, ...$this->privateStorageIssues()];
        $approvalIssues = $this->paymentProvider->manualGcashProductionLaunchIssues();
        $openWork = $this->openWorkCounts();

        if (array_sum($openWork) > 0) {
            $warnings[] = 'existing_manual_gcash_work_requires_continued_staff_resolution';
        }

        $technicalIssues = array_values(array_unique($technicalIssues));

        return [
            'environment' => (string) config('app.env'),
            'provider' => $provider,
            'technical_ready' => $technicalIssues === [],
            'approvals_ready' => $approvalIssues === [],
            'ready_to_activate' => $technicalIssues === [] && $approvalIssues === [],
            'active' => $provider === PaymentProviderService::MANUAL_GCASH
                && $technicalIssues === []
                && $approvalIssues === [],
            'technical_issues' => $technicalIssues,
            'approval_issues' => $approvalIssues,
            'warnings' => $warnings,
            'failed_jobs' => $failedJobs,
            'scheduler' => $schedulerStatus,
            'active_admins' => $activeAdmins,
            'active_receptionists' => $activeReceptionists,
            'open_work' => $openWork,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function schemaIssues(): array
    {
        $requiredTables = [
            'bookings',
            'payments',
            'manual_gcash_configurations',
            'manual_gcash_submissions',
            'manual_gcash_reconciliations',
            'manual_gcash_refunds',
            'payment_access_sessions',
            'jobs',
            'failed_jobs',
            'scheduler_heartbeats',
            'users',
        ];

        $issues = [];
        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $issues[] = "required_table_missing:{$table}";
            }
        }

        $requiredColumns = [
            'bookings' => ['tax_rate', 'downpayment_percentage'],
            'payments' => [
                'lifecycle_status', 'payment_due_at', 'proof_submitted_at',
                'review_due_at', 'review_hold_until', 'submission_attempts',
            ],
            'manual_gcash_submissions' => [
                'proof_retention_expires_at', 'proof_disposal_started_at',
                'proof_deleted_at', 'proof_deletion_reason',
            ],
            'manual_gcash_refunds' => [
                'proof_retention_expires_at', 'proof_disposal_started_at',
                'proof_deleted_at', 'proof_deletion_reason',
            ],
            'payment_access_sessions' => ['revoked_at', 'revocation_reason'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (Schema::hasTable($table) && ! Schema::hasColumns($table, $columns)) {
                $issues[] = "required_columns_missing:{$table}";
            }
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    private function privateStorageIssues(): array
    {
        $issues = [];
        $privateRoot = $this->normalizedPath(storage_path('app/private'));

        foreach (['manual_gcash', 'manual_gcash_proofs', 'manual_gcash_refunds'] as $disk) {
            $configuration = config("filesystems.disks.{$disk}", []);
            $root = $this->normalizedPath((string) ($configuration['root'] ?? ''));

            if (($configuration['driver'] ?? null) !== 'local'
                || ($configuration['visibility'] ?? null) !== 'private'
                || ! (bool) ($configuration['throw'] ?? false)
                || $root === ''
                || ! str_starts_with($root, $privateRoot)) {
                $issues[] = "private_storage_invalid:{$disk}";
            }
        }

        return $issues;
    }

    /**
     * @return array<string, int>
     */
    private function openWorkCounts(): array
    {
        return [
            'proof_reviews' => Schema::hasTable('manual_gcash_submissions')
                ? ManualGcashSubmission::query()->whereIn('status', [
                    ManualGcashSubmission::STATUS_PENDING,
                    ManualGcashSubmission::STATUS_ESCALATED,
                ])->count()
                : 0,
            'reconciliation_exceptions' => Schema::hasTable('manual_gcash_reconciliations')
                ? ManualGcashReconciliation::query()
                    ->where('status', ManualGcashReconciliation::STATUS_EXCEPTION_OPEN)
                    ->count()
                : 0,
            'incomplete_refunds' => Schema::hasTable('manual_gcash_refunds')
                ? ManualGcashRefund::query()
                    ->where('status', ManualGcashRefund::STATUS_APPROVED)
                    ->count()
                : 0,
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return strtolower((string) parse_url(trim($url), PHP_URL_SCHEME)) === 'https'
            && is_string(parse_url(trim($url), PHP_URL_HOST));
    }

    private function normalizedPath(string $path): string
    {
        return strtolower(str_replace('\\', '/', rtrim(trim($path), '/\\')));
    }

    private function isProduction(): bool
    {
        return strtolower(trim((string) config('app.env'))) === 'production';
    }
}
