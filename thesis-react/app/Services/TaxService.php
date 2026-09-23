<?php

namespace App\Services;

use App\Models\SystemSetting;

class TaxService
{
    public function rate(): float
    {
        $fallbackPercentage = (float) config('payments.tax_rate', 0.12) * 100;
        $percentage = (float) SystemSetting::read('tax_percentage', $fallbackPercentage);

        return $this->normalizeRate($percentage / 100);
    }

    public function calculate(float $amountInclusive, ?float $rate = null): float
    {
        $amountInclusive = round(max(0, $amountInclusive), 2);
        $rate = $rate === null ? $this->rate() : $this->normalizeRate($rate);

        if ($rate <= 0) {
            return 0.0;
        }

        $divisor = 1 + $rate;
        if ($divisor <= 0) {
            return 0.0;
        }

        return round($amountInclusive - ($amountInclusive / $divisor), 2);
    }

    public function apply(float $amountInclusive, ?float $rate = null): array
    {
        $total = round(max(0, $amountInclusive), 2);
        $effectiveRate = $rate === null ? $this->rate() : $this->normalizeRate($rate);
        $taxAmount = $this->calculate($total, $effectiveRate);
        $amountBeforeTax = round(max(0, $total - $taxAmount), 2);

        return [
            'subtotal' => $total,
            'amount_before_tax' => $amountBeforeTax,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'tax_rate' => $effectiveRate,
        ];
    }

    private function normalizeRate(float $rate): float
    {
        return round(min(1, max(0, $rate)), 5);
    }
}
