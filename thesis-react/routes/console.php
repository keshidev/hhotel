<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('scheduler:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5);

// ─────────────────────────────────────────────────────────────────────────────
// Daily Notification Schedules
// ─────────────────────────────────────────────────────────────────────────────

Schedule::command('notifications:daily --summary')->dailyAt('08:00');
Schedule::command('notifications:daily --arrivals')->dailyAt('07:00');
Schedule::command('notifications:daily --late-checkin')->dailyAt('18:00');
Schedule::command('notifications:daily --pending')->dailyAt('09:00');

// ─────────────────────────────────────────────────────────────────────────────
// Manual GCash operations
// ─────────────────────────────────────────────────────────────────────────────

Schedule::command('payments:manual-gcash-escalate')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/manual-gcash-review.log'));

Schedule::command('payments:manual-gcash-operations-health')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/manual-gcash-health.log'));

Schedule::command('payments:manual-gcash-prune-evidence')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/manual-gcash-evidence-retention.log'));

Schedule::command('promos:reconcile-usage')
    ->dailyAt('02:55')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/promo-usage-reconcile.log'));

Schedule::command('contact-inquiries:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/contact-inquiry-retention.log'));

Schedule::command('cms:prune-images')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cms-image-retention.log'));

// ─────────────────────────────────────────────────────────────────────────────
// Abandoned Booking Expiry
// Runs every 15 minutes to cancel pending bookings that were never paid.
// Prevents abandoned bookings from locking room inventory indefinitely.
// Threshold: bookings pending > 30 minutes with no completed payment.
// ─────────────────────────────────────────────────────────────────────────────

Schedule::command('bookings:expire-abandoned')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/bookings-expiry.log'));

// ─────────────────────────────────────────────────────────────────────────────
// Misc
// ─────────────────────────────────────────────────────────────────────────────

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
