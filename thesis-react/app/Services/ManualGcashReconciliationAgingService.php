<?php

namespace App\Services;

use App\Models\ManualGcashSubmission;
use Carbon\Carbon;

class ManualGcashReconciliationAgingService
{
    public function maxAgeMinutes(): int
    {
        return max(60, (int) config(
            'payment.manual_gcash.monitoring.reconciliation_max_age_minutes',
            1440
        ));
    }

    public function dueSoonMinutes(): int
    {
        return min($this->maxAgeMinutes(), max(1, (int) config(
            'payment.manual_gcash.monitoring.reconciliation_due_soon_minutes',
            240
        )));
    }

    public function unreconciledCount(): int
    {
        return $this->unreconciledQuery()->count();
    }

    public function overdueCount(): int
    {
        $cutoff = now()->subMinutes($this->maxAgeMinutes());

        return $this->unreconciledQuery()
            ->where(function ($query) use ($cutoff) {
                $query->where('reviewed_at', '<=', $cutoff)
                    ->orWhere(function ($fallback) use ($cutoff) {
                        $fallback->whereNull('reviewed_at')
                            ->where('submitted_at', '<=', $cutoff);
                    });
            })
            ->count();
    }

    public function oldestAgeMinutes(): ?int
    {
        $submission = $this->unreconciledQuery()
            ->orderByRaw('COALESCE(reviewed_at, submitted_at, created_at) ASC')
            ->first(['reviewed_at', 'submitted_at', 'created_at']);

        return $submission ? $this->ageMinutes($this->baseTime($submission)) : null;
    }

    /**
     * @return array<string, int|string>
     */
    public function timingFor(ManualGcashSubmission $submission): array
    {
        $baseTime = $this->baseTime($submission);
        $dueAt = $baseTime->copy()->addMinutes($this->maxAgeMinutes());
        $now = now();
        $remainingMinutes = (int) ceil(($dueAt->timestamp - $now->timestamp) / 60);
        $status = $remainingMinutes <= 0
            ? 'overdue'
            : ($remainingMinutes <= $this->dueSoonMinutes() ? 'due_soon' : 'on_time');

        return [
            'status' => $status,
            'due_at' => $dueAt->toIso8601String(),
            'age_minutes' => $this->ageMinutes($baseTime),
            'minutes_remaining' => max(0, $remainingMinutes),
            'overdue_minutes' => max(0, -$remainingMinutes),
        ];
    }

    private function unreconciledQuery()
    {
        return ManualGcashSubmission::query()
            ->where('status', ManualGcashSubmission::STATUS_APPROVED)
            ->whereDoesntHave('reconciliation');
    }

    private function baseTime(ManualGcashSubmission $submission): Carbon
    {
        return Carbon::parse($submission->reviewed_at ?? $submission->submitted_at ?? $submission->created_at);
    }

    private function ageMinutes(Carbon $baseTime): int
    {
        return max(0, (int) floor((now()->timestamp - $baseTime->timestamp) / 60));
    }
}
