<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_code_usages', function (Blueprint $table) {
            $table->string('status', 20)->default('reserved')->after('discount_amount')->index();
            $table->timestamp('reserved_at')->nullable()->after('status');
            $table->timestamp('consumed_at')->nullable()->after('reserved_at');
            $table->timestamp('released_at')->nullable()->after('consumed_at');
            $table->string('release_reason')->nullable()->after('released_at');
            $table->index(['promo_code_id', 'guest_email', 'status'], 'pcu_promo_email_status_idx');
        });

        DB::table('promo_code_usages')->orderBy('id')->chunkById(200, function ($usages) {
            foreach ($usages as $usage) {
                $bookingStatus = DB::table('bookings')->where('id', $usage->booking_id)->value('booking_status');
                $status = match ($bookingStatus) {
                    'confirmed', 'checked_in', 'checked_out' => 'consumed',
                    'cancelled', 'rejected', 'no_show' => 'released',
                    default => 'reserved',
                };

                DB::table('promo_code_usages')->where('id', $usage->id)->update([
                    'status' => $status,
                    'reserved_at' => $usage->used_at,
                    'consumed_at' => $status === 'consumed' ? $usage->used_at : null,
                    'released_at' => $status === 'released' ? now() : null,
                    'release_reason' => $status === 'released'
                        ? 'historical_' . ($bookingStatus ?: 'terminal')
                        : null,
                ]);
            }
        });

        DB::table('promo_codes')->orderBy('id')->chunkById(200, function ($promoCodes) {
            foreach ($promoCodes as $promoCode) {
                $consumed = DB::table('promo_code_usages')
                    ->where('promo_code_id', $promoCode->id)
                    ->where('status', 'consumed')
                    ->count();
                DB::table('promo_codes')->where('id', $promoCode->id)->update(['total_used' => $consumed]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('promo_code_usages', function (Blueprint $table) {
            $table->dropIndex('pcu_promo_email_status_idx');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'reserved_at', 'consumed_at', 'released_at', 'release_reason']);
        });
    }
};
