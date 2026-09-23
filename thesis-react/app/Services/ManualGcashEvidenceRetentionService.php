<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Models\Booking;
use App\Models\ManualGcashReconciliation;
use App\Models\ManualGcashRefund;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ManualGcashEvidenceRetentionService
{
    private const DISPOSAL_CLAIM_MINUTES = 15;

    public function retentionDays(): int
    {
        return max(30, min(3650, (int) config('payment.manual_gcash.proof_retention_days', 180)));
    }

    public function prune(bool $dryRun = false, int $limit = 500): array
    {
        $stats = [
            'eligible' => 0,
            'deleted' => 0,
            'already_missing' => 0,
            'held' => 0,
            'not_due' => 0,
            'processing' => 0,
            'errors' => 0,
        ];
        $limit = max(1, min(5000, $limit));

        $this->scanSubmissions($stats, $dryRun, $limit);
        $this->scanRefunds($stats, $dryRun, $limit);

        return $stats;
    }

    private function scanSubmissions(array &$stats, bool $dryRun, int $limit): void
    {
        ManualGcashSubmission::query()
            ->whereNull('proof_deleted_at')
            ->with([
                'booking.cancellation',
                'booking.cancellationApprovalRequests.manualGcashRefund',
                'payment',
                'reconciliation',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($submissions) use (&$stats, $dryRun, $limit) {
                foreach ($submissions as $submission) {
                    $this->inspect(
                        $submission,
                        $this->submissionRetentionExpiry($submission),
                        'ManualGcashSubmission',
                        'Manual GCash Payment Proof Retired',
                        $stats,
                        $dryRun,
                        $limit
                    );
                }

                return $stats['eligible'] < $limit;
            });
    }

    private function scanRefunds(array &$stats, bool $dryRun, int $limit): void
    {
        if ($stats['eligible'] >= $limit) {
            return;
        }

        ManualGcashRefund::query()
            ->whereNull('proof_deleted_at')
            ->whereNotNull('proof_path')
            ->orderBy('id')
            ->chunkById(100, function ($refunds) use (&$stats, $dryRun, $limit) {
                foreach ($refunds as $refund) {
                    $expiry = $refund->status === ManualGcashRefund::STATUS_COMPLETED && $refund->processed_at
                        ? $refund->processed_at->copy()->addDays($this->retentionDays())
                        : null;

                    $this->inspect(
                        $refund,
                        $expiry,
                        'ManualGcashRefund',
                        'Manual GCash Refund Proof Retired',
                        $stats,
                        $dryRun,
                        $limit
                    );
                }

                return $stats['eligible'] < $limit;
            });
    }

    private function inspect(
        Model $evidence,
        ?Carbon $expiry,
        string $modelType,
        string $auditAction,
        array &$stats,
        bool $dryRun,
        int $limit
    ): void {
        if ($stats['eligible'] >= $limit) {
            return;
        }

        if (! $expiry) {
            $stats['held']++;

            return;
        }

        if ($expiry->isFuture()) {
            $stats['not_due']++;
            if (! $dryRun && ! $evidence->proof_retention_expires_at?->equalTo($expiry)) {
                $evidence->forceFill(['proof_retention_expires_at' => $expiry])->save();
            }

            return;
        }

        $stats['eligible']++;
        if ($dryRun) {
            return;
        }

        $claim = $this->claim($evidence, $expiry);
        if ($claim === null) {
            $stats['processing']++;

            return;
        }

        try {
            $disk = Storage::disk($claim['disk']);
            $existed = $disk->exists($claim['path']);
            if ($existed && ! $disk->delete($claim['path'])) {
                throw new \RuntimeException('Evidence storage refused deletion.');
            }

            $deletedAt = now();
            $evidence->newQuery()->whereKey($claim['id'])->update([
                'proof_retention_expires_at' => $expiry,
                'proof_disposal_started_at' => null,
                'proof_deleted_at' => $deletedAt,
                'proof_deletion_reason' => $existed
                    ? 'retention_period_expired'
                    : 'retention_period_expired_file_missing',
                'updated_at' => $deletedAt,
            ]);

            $stats[$existed ? 'deleted' : 'already_missing']++;
            AuditHelper::log(
                actionActivity: $auditAction,
                modulePage: 'Payment Module',
                modelType: $modelType,
                modelId: $claim['id'],
                recordAffected: $modelType.' #'.$claim['id'],
                oldValues: ['proof_available' => $existed],
                newValues: [
                    'proof_available' => false,
                    'retention_expired_at' => $expiry->toDateTimeString(),
                    'outcome' => $existed ? 'deleted' : 'already_missing',
                ],
                action: 'retired',
                actorLabel: 'Manual GCash Evidence Retention (System)'
            );
        } catch (\Throwable $exception) {
            $stats['errors']++;
            $evidence->newQuery()->whereKey($claim['id'])->whereNull('proof_deleted_at')->update([
                'proof_disposal_started_at' => null,
                'updated_at' => now(),
            ]);
            Log::error('Manual GCash evidence retirement failed', [
                'model_type' => $modelType,
                'model_id' => $claim['id'],
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function claim(Model $evidence, Carbon $expiry): ?array
    {
        return DB::transaction(function () use ($evidence, $expiry) {
            $locked = $evidence->newQuery()->whereKey($evidence->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->proof_deleted_at) {
                return null;
            }

            if ($locked->proof_disposal_started_at
                && $locked->proof_disposal_started_at->isAfter(now()->subMinutes(self::DISPOSAL_CLAIM_MINUTES))) {
                return null;
            }

            $locked->forceFill([
                'proof_retention_expires_at' => $expiry,
                'proof_disposal_started_at' => now(),
            ])->save();

            return [
                'id' => (int) $locked->getKey(),
                'disk' => (string) $locked->proof_disk,
                'path' => (string) $locked->proof_path,
            ];
        }, 3);
    }

    private function submissionRetentionExpiry(ManualGcashSubmission $submission): ?Carbon
    {
        if (in_array($submission->status, [
            ManualGcashSubmission::STATUS_PENDING,
            ManualGcashSubmission::STATUS_ESCALATED,
        ], true)) {
            return null;
        }

        $payment = $submission->payment;
        $reconciliation = $submission->reconciliation;
        if (! $payment || ! $submission->booking) {
            return null;
        }

        if ($reconciliation?->status === ManualGcashReconciliation::STATUS_EXCEPTION_OPEN
            || $reconciliation?->resolution === ManualGcashReconciliation::RESOLUTION_PAYMENT_REVIEW
            || $payment->lifecycle_status === Payment::LIFECYCLE_PAID_UNDER_REVIEW) {
            return null;
        }

        $completedRefund = $submission->booking->cancellationApprovalRequests
            ->pluck('manualGcashRefund')
            ->filter(fn ($refund) => $refund?->status === ManualGcashRefund::STATUS_COMPLETED && $refund->processed_at)
            ->sortByDesc('processed_at')
            ->first();

        if ($payment->lifecycle_status === Payment::LIFECYCLE_REFUND_REQUIRED && ! $completedRefund) {
            return null;
        }

        $finalEvent = $this->finalBookingEvent($submission->booking, $completedRefund);

        return $finalEvent?->copy()->addDays($this->retentionDays());
    }

    private function finalBookingEvent(Booking $booking, ?ManualGcashRefund $completedRefund): ?Carbon
    {
        if ($completedRefund?->processed_at) {
            return $completedRefund->processed_at;
        }

        if ($booking->booking_status === 'cancelled') {
            return $booking->cancellation?->cancelled_at ?? $booking->updated_at;
        }

        if ($booking->booking_status === 'no_show') {
            return $booking->no_show_marked_at ?? $booking->check_out;
        }

        return $booking->check_out?->isPast() ? $booking->check_out : null;
    }
}
