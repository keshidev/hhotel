<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('staff_recording_key')->nullable()->after('notes');
            $table->unique('staff_recording_key', 'payments_staff_recording_key_uq');
        });

        Schema::table('manual_gcash_submissions', function (Blueprint $table) {
            $table->string('submission_source', 32)
                ->default('customer_proof')
                ->after('attempt_number');
            $table->index('submission_source', 'manual_gcash_submission_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('manual_gcash_submissions', function (Blueprint $table) {
            $table->dropIndex('manual_gcash_submission_source_idx');
            $table->dropColumn('submission_source');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_staff_recording_key_uq');
            $table->dropColumn('staff_recording_key');
        });
    }
};
