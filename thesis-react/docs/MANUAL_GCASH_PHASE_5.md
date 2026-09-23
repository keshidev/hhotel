# Manual GCash Migration — Phase 5 Audited Refund Completion

## Status

- **Implementation:** Complete
- **Scope:** Manual GCash refund approval, completion evidence, customer notification, and audit controls
- **Automated outbound refund:** Not supported and intentionally prohibited
- **Production payment provider:** Must remain `disabled` until launch approval

## Workflow

1. A cancellation approval or resolved reconciliation exception creates a refund obligation.
2. Approval changes the request to `refund_pending`; it does not claim that money was sent.
3. An administrator sends the refund from the official hotel GCash account outside the application.
4. Completion requires the recipient name and account, GCash reference, processed date and time, reason, processor, and proof.
5. The system creates an immutable accounting refund payment and marks the source payment lifecycle `refunded` only after all evidence passes validation.
6. The customer receives a refund-completed email after the database transaction commits.

## Integrity and Privacy Controls

- Refund amount is server-controlled and cannot exceed the remaining refundable amount.
- GCash refund references are normalized and globally unique.
- Recipient accounts are encrypted at rest and only the final four digits are returned by the API.
- Refund proof is stored on a private disk and served only through the authenticated administrator route.
- Proof content is validated independently of its filename and verified against its SHA-256 hash whenever viewed.
- Failed or duplicate submissions delete newly uploaded proof so orphaned evidence is not retained.
- Audit records never include the full recipient account.

## Roles

- Receptionists may identify reconciliation exceptions but cannot approve exceptions or complete refunds.
- Administrators approve refund obligations and record completed outbound GCash transfers.
- The application never initiates a GCash transfer.

## Deployment Safety

- Deploy backend and run the migration on staging first.
- Verify missing-evidence rejection, successful completion, duplicate-reference rejection, private proof access, and customer email delivery.
- Deploy identical code to production only after staging passes.
- Keep production `PAYMENT_PROVIDER=disabled` until a separate launch decision.
