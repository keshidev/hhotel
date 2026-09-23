# Manual GCash Phase 10 — Reconciliation Aging Control

## Purpose

Approved manual GCash payments must be matched against the official merchant statement within 24 hours. This phase makes overdue reconciliation work visible and operationally unhealthy instead of allowing it to remain unnoticed indefinitely.

## Rules

- The reconciliation clock starts when staff approves the payment proof.
- Legacy records without `reviewed_at` use `submitted_at` as the safe fallback.
- A payment is **Due Soon** during the final 4 hours of the 24-hour window.
- A payment is **Overdue** after 24 hours without a reconciliation record.
- Overdue status is an alert only. It never automatically approves, rejects, cancels, or refunds a payment.
- Manual GCash remains the only online payment flow.

## Configuration

```env
MANUAL_GCASH_RECONCILIATION_MAX_AGE_MINUTES=1440
MANUAL_GCASH_RECONCILIATION_DUE_SOON_MINUTES=240
```

## Verification

```bash
php artisan payments:manual-gcash-operations-health; echo "EXIT=$?"
curl -s -w "\nHTTP %{http_code}\n" "https://staging.hhotelbooking.com/api/system/manual-gcash-health"
```

Healthy operations return `EXIT=0` and HTTP `200`. An overdue unreconciled payment reports `stale_unreconciled_payments`, returns `EXIT=1`, and the endpoint returns HTTP `503` until staff reconciles or flags the record.
