<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->after('lifecycle_updated_at');
            $table->timestamp('proof_submitted_at')->nullable()->after('payment_due_at');
            $table->timestamp('review_due_at')->nullable()->after('proof_submitted_at');
            $table->timestamp('review_hold_until')->nullable()->after('review_due_at');
            $table->unsignedTinyInteger('submission_attempts')->default(0)->after('review_hold_until');
            $table->index(['provider', 'payment_status', 'payment_due_at'], 'payments_manual_due_index');
        });

        Schema::create('manual_gcash_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('transaction_reference', 80);
            $table->string('normalized_transaction_reference', 64);
            $table->string('active_reference_claim', 64)->nullable()->unique();
            $table->string('sender_name', 120);
            $table->decimal('submitted_amount', 12, 2);
            $table->timestamp('paid_at');
            $table->string('status', 32)->default('pending_verification');
            $table->timestamp('declaration_accepted_at');
            $table->string('proof_disk', 32)->default('manual_gcash_proofs');
            $table->string('proof_path');
            $table->string('proof_original_name');
            $table->string('proof_mime_type', 50);
            $table->unsignedBigInteger('proof_size');
            $table->char('proof_sha256', 64);
            $table->timestamp('submitted_at');
            $table->timestamp('review_due_at');
            $table->timestamp('escalation_due_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->boolean('admin_override')->default(false);
            $table->timestamps();

            $table->unique(['payment_id', 'attempt_number'], 'manual_gcash_payment_attempt_unique');
            $table->index(['status', 'review_due_at'], 'manual_gcash_review_due_index');
            $table->index(['status', 'escalation_due_at'], 'manual_gcash_escalation_due_index');
            $table->index('normalized_transaction_reference', 'manual_gcash_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_gcash_submissions');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_manual_due_index');
            $table->dropColumn([
                'payment_due_at',
                'proof_submitted_at',
                'review_due_at',
                'review_hold_until',
                'submission_attempts',
            ]);
        });
    }
};
