<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('lifecycle_status', 40)->default('pending')->after('payment_status');
            $table->text('lifecycle_message')->nullable()->after('lifecycle_status');
            $table->timestamp('lifecycle_updated_at')->nullable()->after('lifecycle_message');
            $table->index('lifecycle_status', 'payments_lifecycle_status_index');
        });

        DB::table('payments')->update([
            'lifecycle_status' => 'pending',
            'lifecycle_updated_at' => DB::raw('updated_at'),
        ]);

        DB::table('payments')->where('payment_status', 'completed')->update(['lifecycle_status' => 'paid']);
        DB::table('payments')->where('payment_status', 'failed')->update(['lifecycle_status' => 'failed']);
        DB::table('payments')->where('payment_status', 'refunded')->update(['lifecycle_status' => 'refunded']);
        DB::table('payments')->where('provider_operation_status', 'source_ready')->update(['lifecycle_status' => 'authorized']);
        DB::table('payments')->whereIn('provider_operation_status', ['payment_creating', 'payment_created'])->update(['lifecycle_status' => 'capture_pending']);
        DB::table('payments')->where('provider_operation_status', 'payment_create_failed')->update([
            'lifecycle_status' => 'capture_unknown',
            'lifecycle_message' => DB::raw('provider_operation_error'),
        ]);
        DB::table('payments')->where('provider_operation_status', 'payment_capture_blocked')->update([
            'lifecycle_status' => 'failed',
            'lifecycle_message' => DB::raw('provider_operation_error'),
        ]);
        DB::table('payments')->whereIn('provider_operation_status', ['settlement_review_required', 'paid_under_review'])->update([
            'lifecycle_status' => 'paid_under_review',
            'lifecycle_message' => DB::raw('provider_operation_error'),
        ]);
        DB::table('payments')->where('provider_operation_status', 'refund_required')->update([
            'lifecycle_status' => 'refund_required',
            'lifecycle_message' => DB::raw('provider_operation_error'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_lifecycle_status_index');
            $table->dropColumn(['lifecycle_status', 'lifecycle_message', 'lifecycle_updated_at']);
        });
    }
};
