<?php

namespace App\Services;

use App\Models\Payment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class FinancialReportingService
{
    public function collectionsQuery(?DateTimeInterface $start = null, ?DateTimeInterface $end = null): Builder
    {
        return $this->constrainCollections(Payment::query(), $start, $end);
    }

    public function refundsQuery(?DateTimeInterface $start = null, ?DateTimeInterface $end = null): Builder
    {
        return $this->constrainRefunds(Payment::query(), $start, $end);
    }

    public function ledgerQuery(?DateTimeInterface $start = null, ?DateTimeInterface $end = null): Builder
    {
        return $this->constrainLedger(Payment::query(), $start, $end);
    }

    public function constrainCollections(
        Builder|Relation $query,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null
    ): Builder|Relation {
        $query->where('payment_type', '!=', Payment::TYPE_REFUND)
            ->where('payment_status', 'completed')
            ->whereNotNull('paid_at');

        return $this->constrainPeriod($query, $start, $end);
    }

    public function constrainRefunds(
        Builder|Relation $query,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null
    ): Builder|Relation {
        $query->where('payment_type', Payment::TYPE_REFUND)
            ->whereIn('payment_status', ['completed', 'refunded'])
            ->whereNotNull('paid_at');

        return $this->constrainPeriod($query, $start, $end);
    }

    public function constrainLedger(
        Builder|Relation $query,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null
    ): Builder|Relation {
        $query->where(function (Builder $ledger) {
            $ledger->where(function (Builder $collections) {
                $collections->where('payment_type', '!=', Payment::TYPE_REFUND)
                    ->where('payment_status', 'completed');
            })->orWhere(function (Builder $refunds) {
                $refunds->where('payment_type', Payment::TYPE_REFUND)
                    ->whereIn('payment_status', ['completed', 'refunded']);
            });
        })->whereNotNull('paid_at');

        return $this->constrainPeriod($query, $start, $end);
    }

    public function summary(?DateTimeInterface $start = null, ?DateTimeInterface $end = null): array
    {
        $grossRevenue = round((float) $this->collectionsQuery($start, $end)->sum('amount'), 2);
        $totalRefunds = round((float) $this->refundsQuery($start, $end)->sum('amount'), 2);

        return [
            'gross_revenue' => $grossRevenue,
            'total_refunds' => $totalRefunds,
            'net_revenue' => round($grossRevenue - $totalRefunds, 2),
        ];
    }

    public function isCollection(Payment $payment): bool
    {
        return $payment->payment_type !== Payment::TYPE_REFUND
            && $payment->payment_status === 'completed'
            && $payment->paid_at !== null;
    }

    public function isRefund(Payment $payment): bool
    {
        return $payment->payment_type === Payment::TYPE_REFUND
            && in_array($payment->payment_status, ['completed', 'refunded'], true)
            && $payment->paid_at !== null;
    }

    public function netLedgerSql(): string
    {
        return "CASE
            WHEN payment_type = 'refund' AND payment_status IN ('completed', 'refunded') THEN -amount
            WHEN payment_type != 'refund' AND payment_status = 'completed' THEN amount
            ELSE 0
        END";
    }

    private function constrainPeriod(
        Builder|Relation $query,
        ?DateTimeInterface $start,
        ?DateTimeInterface $end
    ): Builder|Relation {
        if ($start !== null) {
            $query->where('paid_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('paid_at', '<=', $end);
        }

        return $query;
    }
}
