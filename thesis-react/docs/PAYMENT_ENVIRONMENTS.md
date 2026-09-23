# Payment environments

Manual GCash is the only supported online payment flow. Production and staging must use separate databases, private files, deployment directories, merchant records, and test bookings.

`PAYMENT_PROVIDER` supports two values:

- `manual_gcash` enables the protected proof-submission and staff-review flow.
- `disabled` safely stops new online payment handoffs.

After a booking is created, the guest continues directly to the protected payment page using the one-time bootstrap credential issued by the server.

## Staging

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.hhotelbooking.com/api
FRONTEND_URL=https://staging.hhotelbooking.com

DB_DATABASE=your_separate_staging_database
DB_USERNAME=your_separate_staging_user
DB_PASSWORD=your_staging_database_password

PAYMENT_PROVIDER=manual_gcash
```

Use test bookings and approved training transaction records only. Never copy real customer records into staging.

After changing staging configuration:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan payments:health --require-operational
php artisan system:release-readiness --allow-non-production
```

Test booking creation, direct payment handoff, proof upload, rejection and correction, staff approval, reconciliation, cancellation, and refund evidence on staging.

## Production

Production activation requires the manual GCash launch gates, a fresh verified backup, reviewed rollback steps, trained staff, and explicit production approval.

```dotenv
APP_ENV=production
APP_DEBUG=false
PAYMENT_PROVIDER=manual_gcash
MANUAL_GCASH_LAUNCH_MERCHANT_VERIFIED=true
MANUAL_GCASH_LAUNCH_STAFF_TRAINED=true
MANUAL_GCASH_LAUNCH_BACKUP_CONFIRMED=true
MANUAL_GCASH_LAUNCH_ROLLBACK_REVIEWED=true
MANUAL_GCASH_PRODUCTION_LAUNCH_APPROVED=true
```

Run `php artisan payments:health --require-operational` and the strict production release-readiness command after caching configuration and before accepting a live booking.
