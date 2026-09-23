<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('status', 20)->default('received')->after('payload');
            $table->unsignedInteger('attempt_count')->default(0)->after('status');
            $table->timestamp('processing_started_at')->nullable()->after('attempt_count');
            $table->timestamp('failed_at')->nullable()->after('processing_started_at');
            $table->text('last_error')->nullable()->after('failed_at');
            $table->index(['provider', 'status'], 'webhook_events_provider_status_index');
        });

        DB::table('webhook_events')
            ->whereNotNull('processed_at')
            ->update(['status' => 'processed']);
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex('webhook_events_provider_status_index');
            $table->dropColumn([
                'status',
                'attempt_count',
                'processing_started_at',
                'failed_at',
                'last_error',
            ]);
        });
    }
};
