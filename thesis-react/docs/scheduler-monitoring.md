# Scheduler Monitoring

The application records a scheduler heartbeat every minute in the
`scheduler_heartbeats` table. A heartbeat older than three minutes is unhealthy.

## Required cron jobs

Run Laravel's scheduler every minute for each environment:

```text
/usr/bin/php /home/USERNAME/domains/hhotelbooking.com/public_html/staging/api/artisan schedule:run
/usr/bin/php /home/USERNAME/domains/hhotelbooking.com/public_html/api/artisan schedule:run
```

Keep staging and production as separate Hostinger cron jobs.

## Health checks

Check from SSH:

```bash
php artisan scheduler:health
```

Check from an external uptime monitor:

```text
https://staging.hhotelbooking.com/api/system/scheduler-health
https://hhotelbooking.com/api/system/scheduler-health
```

Healthy responses return HTTP 200 with `{"status":"ok"}`. Missing or stale
heartbeats return HTTP 503 so an external monitor can send an alert even when
Laravel's scheduler has stopped.

The endpoint intentionally exposes no timestamps, database details, or server
paths and sends no-cache headers.
