# Manual GCash Migration — Phase 6 Evidence Retention and Secure Disposal

## Status

- **Implementation:** Complete
- **Scope:** Payment-proof and refund-proof retention, disposal audit, and retired-evidence handling
- **Retention period:** 180 days after the final checkout, cancellation, or completed refund event
- **Production payment provider:** Must remain `disabled` until launch approval

## Retention Rules

1. Pending or escalated proof reviews are never pruned.
2. Open reconciliation exceptions, payment-review resolutions, and refund-required payments are never pruned.
3. A completed refund becomes the final event for both the original payment proof and refund proof.
4. A cancelled booking without a completed refund uses its cancellation time.
5. A no-show uses its no-show time; otherwise an ended stay uses checkout time.
6. The scheduled command removes only the private file. Payment, booking, reference, reconciliation, refund, and immutable audit records remain.
7. Every disposal creates an audit event without exposing a file path, hash, account number, or customer proof contents.

## Operational Controls

- `payments:manual-gcash-prune-evidence --dry-run` reports eligibility without changing data.
- Actual disposal uses a database claim to prevent concurrent workers from deleting the same evidence.
- Interrupted claims become recoverable after 15 minutes.
- Missing files are recorded as already missing instead of silently treated as successful deletion.
- Staff receive HTTP `410 Gone` when requesting evidence that was retired under policy.

## Schedule

The scheduler runs the cleanup daily at `02:45` and writes operational output to:

`storage/logs/manual-gcash-evidence-retention.log`

## Deployment Safety

- Phase 5's `manual_gcash_refunds` table is a required prerequisite.
- The recovery migration creates that table when an older production environment did not receive Phase 5.
- Retention columns and indexes are added idempotently so an interrupted migration can be rerun safely.
- Deploy and migrate staging first.
- Run the dry-run command before allowing actual disposal.
- Verify the configured retention value is exactly `180`.
- Keep production `PAYMENT_PROVIDER=disabled` until the separate launch phase.
