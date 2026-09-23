# Manual GCash Migration — Phase 9 Post-Launch Monitoring

## Status

- **Implementation:** Complete
- **Scope:** Read-only operational monitoring after production activation
- **Payment behavior:** Unchanged
- **Online payment provider:** Manual GCash

## Health Rules

Manual GCash is healthy when the active provider and launch safeguards remain valid and all of these conditions hold:

1. The scheduler heartbeat is current.
2. No failed queue jobs exist.
3. No queued job has remained available for longer than 15 minutes.
4. No proof review has reached its escalation deadline without resolution.
5. No reconciliation exception has remained open longer than 24 hours.
6. No approved refund has remained incomplete longer than 24 hours.

Normal proof reviews, exceptions, and refunds do not fail health before their operational deadlines.

## Commands

```bash
php artisan payments:manual-gcash-operations-health
echo "EXIT=$?"
```

Healthy output ends with:

```text
Manual GCash operations are healthy.
EXIT=0
```

The public monitor endpoint intentionally exposes no counts or internal issue names:

```bash
curl -s -w "\nHTTP %{http_code}\n" https://hhotelbooking.com/api/system/manual-gcash-health
```

Healthy response:

```json
{"status":"ok"}
```

The endpoint returns HTTP `503` with `{"status":"degraded"}` when intervention is required. Configure an HTTPS monitor such as UptimeRobot to check it every five minutes.

## Threshold Configuration

```dotenv
MANUAL_GCASH_RECONCILIATION_EXCEPTION_MAX_AGE_MINUTES=1440
MANUAL_GCASH_REFUND_MAX_AGE_MINUTES=1440
MANUAL_GCASH_QUEUE_JOB_MAX_AGE_MINUTES=15
```

Keep these defaults unless the hotel adopts a documented service-level policy with different response times.
