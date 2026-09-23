<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_gcash_reconciliations')) {
            if (Schema::hasIndex('manual_gcash_reconciliations', 'mgcr_statement_ref_idx')) {
                $this->ensureIndexes();

                return;
            }

            if (DB::table('manual_gcash_reconciliations')->exists()) {
                throw new RuntimeException('The incomplete manual GCash reconciliation table contains data and cannot be rebuilt automatically.');
            }

            Schema::drop('manual_gcash_reconciliations');
        }

        Schema::create('manual_gcash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained('manual_gcash_submissions')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('statement_reference', 80)->nullable();
            $table->string('normalized_statement_reference', 80)->nullable();
            $table->string('matched_reference_claim', 80)->nullable();
            $table->decimal('statement_amount', 12, 2)->nullable();
            $table->timestamp('statement_paid_at')->nullable();
            $table->string('exception_type', 40)->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('resolution', 40)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'reconciled_at'], 'manual_gcash_reconciliation_status_index');
            $table->index(['payment_id', 'status'], 'manual_gcash_reconciliation_payment_index');
        });

        $this->ensureIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_gcash_reconciliations');
    }

    private function ensureIndexes(): void
    {
        Schema::table('manual_gcash_reconciliations', function (Blueprint $table) {
            if (! Schema::hasIndex('manual_gcash_reconciliations', 'mgcr_statement_ref_idx')) {
                $table->index('normalized_statement_reference', 'mgcr_statement_ref_idx');
            }

            if (! Schema::hasIndex('manual_gcash_reconciliations', 'mgcr_matched_ref_uq')) {
                $table->unique('matched_reference_claim', 'mgcr_matched_ref_uq');
            }

            if (! Schema::hasIndex('manual_gcash_reconciliations', 'manual_gcash_reconciliation_status_index')) {
                $table->index(['status', 'reconciled_at'], 'manual_gcash_reconciliation_status_index');
            }

            if (! Schema::hasIndex('manual_gcash_reconciliations', 'manual_gcash_reconciliation_payment_index')) {
                $table->index(['payment_id', 'status'], 'manual_gcash_reconciliation_payment_index');
            }
        });
    }
};
