<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_operation_status', 40)->nullable()->after('provider_reference');
            $table->string('provider_operation_token', 64)->nullable()->after('provider_operation_status');
            $table->timestamp('provider_operation_started_at')->nullable()->after('provider_operation_token');
            $table->timestamp('provider_operation_failed_at')->nullable()->after('provider_operation_started_at');
            $table->text('provider_operation_error')->nullable()->after('provider_operation_failed_at');
            $table->index('provider_operation_status', 'payments_provider_operation_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_provider_operation_status_index');
            $table->dropColumn([
                'provider_operation_status',
                'provider_operation_token',
                'provider_operation_started_at',
                'provider_operation_failed_at',
                'provider_operation_error',
            ]);
        });
    }
};
