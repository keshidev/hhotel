<?php

namespace App\Services;

use App\Models\SystemSetting;

class DownpaymentService
{
    public function percentage(): float
    {
        $percentage = (float) SystemSetting::read('downpayment_percentage', 50);

        return round(min(100, max(1, $percentage)), 2);
    }

    public function rate(): float
    {
        return round($this->percentage() / 100, 4);
    }

    public function calculate(float $total, ?float $percentage = null): float
    {
        $total = round(max(0, $total), 2);
        $effectivePercentage = $percentage === null
            ? $this->percentage()
            : $this->normalizeSnapshotPercentage($percentage);

        return round($total * ($effectivePercentage / 100), 2);
    }

    public function apply(float $total, ?float $percentage = null): array
    {
        $total = round(max(0, $total), 2);
        $effectivePercentage = $percentage === null
            ? $this->percentage()
            : $this->normalizeSnapshotPercentage($percentage);
        $amount = $this->calculate($total, $effectivePercentage);

        return [
            'total' => $total,
            'percentage' => $effectivePercentage,
            'rate' => round($effectivePercentage / 100, 4),
            'amount' => $amount,
            'remaining_balance' => round(max(0, $total - $amount), 2),
        ];
    }

    public function resolvePercentage(?float $snapshot, float $total, ?float $amount): float
    {
        if ($snapshot !== null) {
            return $this->normalizeSnapshotPercentage($snapshot);
        }

        if ($total > 0.009 && $amount !== null) {
            return $this->normalizeSnapshotPercentage(($amount / $total) * 100);
        }

        return $this->percentage();
    }

    private function normalizeSnapshotPercentage(float $percentage): float
    {
        return round(min(100, max(0, $percentage)), 2);
    }
}
