<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const HANDLED_EVENTS = [
        'source.chargeable',
        'source.failed',
        'source.expired',
        'source.cancelled',
        'payment.paid',
        'payment.failed',
    ];

    public function up(): void
    {
        DB::table('webhook_events')
            ->where('provider', 'paymongo')
            ->where('status', 'processed')
            ->whereNotIn('event_type', self::HANDLED_EVENTS)
            ->update(['status' => 'ignored']);
    }

    public function down(): void
    {
        DB::table('webhook_events')
            ->where('provider', 'paymongo')
            ->where('status', 'ignored')
            ->whereNotIn('event_type', self::HANDLED_EVENTS)
            ->update(['status' => 'processed']);
    }
};
