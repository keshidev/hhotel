# Manual GCash Migration — Phase 7 Production Launch Readiness and Rollback Protection

## Status

- **Implementation:** Complete
- **Scope:** Technical preflight, human launch gates, accidental activation protection, and rollback verification
- **Activation:** This phase does not enable production payments
- **Required production provider during deployment:** `disabled`

## Safety Boundary

Production manual GCash checkout is operational only when all of these conditions are true:

1. `PAYMENT_PROVIDER=manual_gcash`.
2. The merchant configuration and private QR integrity check pass.
3. Required Phase 1–6 database tables and columns exist.
4. Database queues, email delivery configuration, scheduler heartbeat, and private evidence disks are ready.
5. At least one active administrator and one active receptionist exist.
6. Failed queue jobs have been resolved.
7. Merchant verification, staff training, database backup, rollback review, and final owner approval are recorded in server configuration.

Changing only `PAYMENT_PROVIDER` cannot accidentally activate production checkout. Missing launch approvals make the provider non-operational and customer booking creation remains blocked.

## Launch Approval Variables

Keep all values `false` while Phase 7 is deployed and tested:

```dotenv
MANUAL_GCASH_LAUNCH_MERCHANT_VERIFIED=false
MANUAL_GCASH_LAUNCH_STAFF_TRAINED=false
MANUAL_GCASH_LAUNCH_BACKUP_CONFIRMED=false
MANUAL_GCASH_LAUNCH_ROLLBACK_REVIEWED=false
MANUAL_GCASH_PRODUCTION_LAUNCH_APPROVED=false
```

Each value is changed to `true` only after the named action is actually complete. Final approval must remain `false` until the business owner explicitly authorizes launch.

## Preflight

Run while production remains disabled:

```bash
php artisan payments:manual-gcash-launch-check
```

The command fails for technical problems. Pending human approvals are warnings during this safe preflight.

Before activation, require every approval:

```bash
php artisan payments:manual-gcash-launch-check --require-approval
```

After approved activation, require the provider to be active and operational:

```bash
php artisan payments:manual-gcash-launch-check --require-active
php artisan payments:health --require-operational
```

## Rollback

Rollback stops new manual GCash bookings without deleting existing payment work:

```dotenv
PAYMENT_PROVIDER=disabled
MANUAL_GCASH_PRODUCTION_LAUNCH_APPROVED=false
```

Then refresh configuration:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan payments:health
```

Expected provider output is `disabled`. Existing proof reviews, reconciliation exceptions, and refunds remain available to authorized staff for resolution.

## Deployment Rule

Deploy identical Phase 7 backend code to staging and production. Verify staging first. Keep production disabled until a separate explicit launch instruction is approved.
