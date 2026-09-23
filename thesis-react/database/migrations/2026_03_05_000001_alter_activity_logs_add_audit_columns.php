<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('activity_logs', 'user_staff_name')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->string('user_staff_name')->nullable()->after('user_id');
            });
        }

        if (!Schema::hasColumn('activity_logs', 'action_activity')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->string('action_activity')->nullable()->after('action');
            });
        }

        if (!Schema::hasColumn('activity_logs', 'module_page')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->string('module_page')->nullable()->after('model_type');
            });
        }

        if (!Schema::hasColumn('activity_logs', 'record_affected')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->string('record_affected')->nullable()->after('module_page');
            });
        }

        $indexStatements = [
            'create index activity_logs_action_activity_index on activity_logs (action_activity)',
            'create index activity_logs_module_page_index on activity_logs (module_page)',
            'create index activity_logs_user_staff_name_index on activity_logs (user_staff_name)',
            'create index activity_logs_created_at_index on activity_logs (created_at)',
        ];

        foreach ($indexStatements as $statement) {
            try {
                DB::statement($statement);
            } catch (\Throwable $e) {
                // Ignore duplicate index/column errors to keep migration idempotent.
            }
        }
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['action_activity']);
            $table->dropIndex(['module_page']);
            $table->dropIndex(['user_staff_name']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['user_staff_name', 'action_activity', 'module_page', 'record_affected']);
        });
    }
};
