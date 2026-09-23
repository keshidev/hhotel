# Manual GCash Migration — Phase 3 Proof and Review Workflow

## Status

- **Implementation:** Complete
- **Online payment integration:** Manual GCash only
- **Production payment provider:** Must remain `disabled` until launch approval
- **Staging payment provider:** May be set to `manual_gcash` for testing

## Customer Flow

1. The customer creates a booking and verifies the booking email.
2. Verification creates a secure HttpOnly bootstrap cookie and starts the 30-minute payment window.
3. The payment page shows the private official merchant QR and exact downpayment.
4. The customer submits the GCash reference, sender, exact amount, payment time, declaration, and proof.
5. Proof submission places the booking on review hold. A screenshot never confirms the booking.
6. The customer sees pending, rejected, escalated, or approved status through the secure payment session.

## Staff Flow

- Admin and receptionist users receive a **GCash Reviews** page.
- Proof files are private and require authenticated staff access.
- A receptionist can approve only an exact reference, amount, and payment-time match.
- An administrator may override a reference or time mismatch with a reason.
- The required amount can never be overridden.
- Approval confirms the booking, records payment, assigns rooms, revokes payment access, and queues confirmation email.
- Rejection provides a reason and permits correction only before the deadline and attempt limit.

## Security and Integrity

- Proof types: JPEG, PNG, WebP, or PDF; maximum 5 MB.
- Actual file content is inspected instead of trusting the filename.
- Files use randomized private paths and SHA-256 integrity checks.
- GCash references are normalized and uniquely reserved for pending and approved reviews.
- Admin-overridden merchant references replace the customer claim as the permanent unique claim.
- Payment times older than the booking are rejected.
- Maximum proof attempts: 3.
- Guest access uses existing hashed database sessions and HttpOnly cookies.
- Terminal booking states revoke active payment access.

## Timing Rules

- Payment and proof deadline: 30 minutes after verification.
- Normal review target: 15 minutes.
- Administrator escalation: 2 hours after proof submission.
- Escalation never automatically cancels the booking.
- `payments:manual-gcash-escalate` is scheduled every 5 minutes.

## Deployment Rule

- Deploy the backend ZIP to both API folders.
- Deploy the staging frontend ZIP to `public_html/staging`.
- Deploy the production frontend ZIP to `public_html`.
- Keep production `PAYMENT_PROVIDER=disabled`.
- Select `PAYMENT_PROVIDER=manual_gcash` only in staging until explicit launch approval.

## Verification

Expected staging health output after configuration and provider selection:

```text
Payment provider: manual_gcash
Payment provider configuration is valid and online booking is available.
```

Expected production health output before launch:

```text
Payment provider: disabled
Configuration is safe, but online payments are disabled.
```
