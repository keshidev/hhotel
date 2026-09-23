# Manual GCash Migration — Phase 4 Reconciliation and Exception Queue

## Status

- **Implementation:** Complete
- **Scope:** Daily merchant-statement reconciliation and discrepancy escalation only
- **Refund execution:** Not included; deferred to a later separately approved phase
- **Production payment provider:** Must remain `disabled` until launch approval

## Workflow

1. Every approved manual GCash payment appears as unreconciled.
2. Admin or receptionist staff compare the booking reference, merchant reference, amount, and paid time with the official merchant statement.
3. Only an exact statement match can be marked reconciled.
4. Missing, duplicate, mismatched, reversed, or uncertain transactions are moved to the administrator exception queue.
5. Opening an exception marks the payment lifecycle as `paid_under_review` without automatically cancelling or refunding the booking.
6. Only an administrator can resolve an exception as an exact match, continued payment review, or `refund_required`.
7. `refund_required` opens the need for the existing audited refund workflow but never sends or completes a refund automatically.

## Integrity Controls

- One reconciliation record exists per approved proof submission.
- A matched merchant reference can belong to only one reconciliation record.
- Reconciliation actions lock the submission, payment, and reconciliation rows in one database transaction.
- Exact-match checks compare normalized reference, amount in centavos, and merchant paid time to the minute.
- Every match, exception, and resolution is written to the audit trail.

## Operations Dashboard

The GCash staff page reports:

- pending proof reviews;
- overdue proof reviews;
- unreconciled approved payments;
- open reconciliation exceptions; and
- payments whose lifecycle is `refund_required`.

## Deployment Safety

- Deploy the backend to staging first and run the migration.
- Test exact reconciliation, exception escalation, and admin resolution in staging.
- Deploy the same backend and frontend code to production only after staging passes.
- Keep production `PAYMENT_PROVIDER=disabled`.
