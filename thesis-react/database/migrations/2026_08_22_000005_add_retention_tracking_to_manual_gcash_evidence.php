<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manual_gcash_submissions') || ! Schema::hasTable('manual_gcash_refunds')) {
            throw new RuntimeException('Phase 6 requires the Phase 3 submissions and Phase 5 refunds tables.');
        }

        $this->addSubmissionColumns();
        $this->addRefundColumns();
    }

    public function down(): void
    {
        Schema::table('manual_gcash_submissions', function (Blueprint $table) {
            $table->dropIndex('mgs_proof_retention_idx');
            $table->dropColumn([
                'proof_retention_expires_at',
                'proof_disposal_started_at',
                'proof_deleted_at',
                'proof_deletion_reason',
            ]);
        });

        Schema::table('manual_gcash_refunds', function (Blueprint $table) {
            $table->dropIndex('mgr_proof_retention_idx');
            $table->dropColumn([
                'proof_retention_expires_at',
                'proof_disposal_started_at',
                'proof_deletion_reason',
            ]);
        });
    }

    private function addSubmissionColumns(): void
    {
        if (! Schema::hasColumn('manual_gcash_submissions', 'proof_retention_expires_at')) {
            Schema::table('manual_gcash_submissions', function (Blueprint $table) {
                $table->timestamp('proof_retention_expires_at')->nullable()->after('proof_sha256');
            });
        }
        if (! Schema::hasColumn('manual_gcash_submissions', 'proof_disposal_started_at')) {
            Schema::table('manual_gcash_submissions', function (Blueprint $table) {
                $table->timestamp('proof_disposal_started_at')->nullable()->after('proof_retention_expires_at');
            });
        }
        if (! Schema::hasColumn('manual_gcash_submissions', 'proof_deleted_at')) {
            Schema::table('manual_gcash_submissions', function (Blueprint $table) {
                $table->timestamp('proof_deleted_at')->nullable()->after('proof_disposal_started_at');
            });
        }
        if (! Schema::hasColumn('manual_gcash_submissions', 'proof_deletion_reason')) {
            Schema::table('manual_gcash_submissions', function (Blueprint $table) {
                $table->string('proof_deletion_reason', 80)->nullable()->after('proof_deleted_at');
            });
        }
        if (! Schema::hasIndex('manual_gcash_submissions', 'mgs_proof_retention_idx')) {
            Schema::table('manual_gcash_submissions', function (Blueprint $table) {
                $table->index(['proof_deleted_at', 'proof_retention_expires_at'], 'mgs_proof_retention_idx');
            });
        }
    }

    private function addRefundColumns(): void
    {
        if (! Schema::hasColumn('manual_gcash_refunds', 'proof_retention_expires_at')) {
            Schema::table('manual_gcash_refunds', function (Blueprint $table) {
                $table->timestamp('proof_retention_expires_at')->nullable()->after('proof_sha256');
            });
        }
        if (! Schema::hasColumn('manual_gcash_refunds', 'proof_disposal_started_at')) {
            Schema::table('manual_gcash_refunds', function (Blueprint $table) {
                $table->timestamp('proof_disposal_started_at')->nullable()->after('proof_retention_expires_at');
            });
        }
        if (! Schema::hasColumn('manual_gcash_refunds', 'proof_deletion_reason')) {
            Schema::table('manual_gcash_refunds', function (Blueprint $table) {
                $table->string('proof_deletion_reason', 80)->nullable()->after('proof_deleted_at');
            });
        }
        if (! Schema::hasIndex('manual_gcash_refunds', 'mgr_proof_retention_idx')) {
            Schema::table('manual_gcash_refunds', function (Blueprint $table) {
                $table->index(['proof_deleted_at', 'proof_retention_expires_at'], 'mgr_proof_retention_idx');
            });
        }
    }
};
