# Release Checklist

Run these checks from the deployed API directory before releasing an environment.

## Security Deployment

1. Back up the current API files, database, and private uploads before replacing files.
2. Extract the backend release over the API directory. Keep the deployed `.env`, `storage`, and `public/storage` link; they are intentionally excluded from the archive.
3. Configure the environment-specific security values:

```dotenv
SANCTUM_EXPIRATION_MINUTES=480
SECURITY_HEADERS_ENABLED=true
SECURITY_HSTS_MAX_AGE=31536000
```

Use only the matching HTTPS frontend origin:

```dotenv
# Staging
CORS_ALLOWED_ORIGINS=https://staging.hhotelbooking.com

# Production
CORS_ALLOWED_ORIGINS=https://hhotelbooking.com
```

4. Refresh Laravel caches and workers:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan view:cache
php artisan queue:restart
```

5. Revoke pre-deployment staff tokens so every administrator and receptionist signs in with the new tab-scoped session behavior:

```bash
php artisan tinker --execute='\Laravel\Sanctum\PersonalAccessToken::query()->delete();'
```

6. Deploy the matching staging or production frontend archive and confirm `.htaccess` was extracted.
7. Sign in again and smoke-test booking, payment proof, inquiry, staff login/logout, and mobile checkout navigation.

## Backup and Restore Evidence

1. Create a fresh database dump.
2. Create a separate archive of private uploaded files.
3. Restore both artifacts into an isolated test location and confirm the restored application can read its booking and payment records.
4. Record that completed restore test:

```bash
php artisan system:verify-backup /absolute/path/database.sql.gz /absolute/path/private-files.zip --restore-test-reference="staging-restore-YYYY-MM-DD"
```

The command records artifact sizes and SHA-256 checksums without storing their absolute paths.

## Final Gate

For staging:

```bash
php artisan system:release-readiness --allow-non-production
```

For production:

```bash
php artisan system:release-readiness
```

Deployment is ready only when every row reports `PASS` and the command exits with code `0`.
