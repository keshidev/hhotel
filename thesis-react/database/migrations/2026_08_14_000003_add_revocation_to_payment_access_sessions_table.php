<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TERMINAL_BOOKING_STATUSES = [
        'confirmed',
        'checked_in',
        'checked_out',
        'cancelled',
        'no_show',
    ];

    public function up(): void
    {
        Schema::table('payment_access_sessions', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
            $table->string('revocation_reason', 100)->nullable()->after('revoked_at');
            $table->index(['booking_id', 'revoked_at'], 'payment_access_sessions_booking_revoked_index');
        });

        DB::table('payment_access_sessions')
            ->whereNull('revoked_at')
            ->whereIn('booking_id', function ($query) {
                $query->select('id')
                    ->from('bookings')
                    ->whereIn('booking_status', self::TERMINAL_BOOKING_STATUSES);
            })
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => 'booking_terminal_backfill',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('payment_access_sessions', function (Blueprint $table) {
            $table->dropIndex('payment_access_sessions_booking_revoked_index');
            $table->dropColumn(['revoked_at', 'revocation_reason']);
        });
    }
};
