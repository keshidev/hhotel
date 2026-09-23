<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cancellations', function (Blueprint $table) {
            $table->string('refund_status', 20)->default('none')->after('refund_amount');
            $table->timestamp('refunded_at')->nullable()->after('refund_status');
            $table->text('staff_note')->nullable()->after('refunded_at');

            $table->index('refund_status');
            $table->index('refunded_at');
        });

        // Backfill: legacy rows that already have a refund_amount should be treated as refunded.
        DB::table('cancellations')
            ->where('refund_amount', '>', 0)
            ->update(['refund_status' => 'refunded']);
    }

    public function down(): void
    {
        Schema::table('cancellations', function (Blueprint $table) {
            $table->dropIndex(['refund_status']);
            $table->dropIndex(['refunded_at']);
            $table->dropColumn(['refund_status', 'refunded_at', 'staff_note']);
        });
    }
};
