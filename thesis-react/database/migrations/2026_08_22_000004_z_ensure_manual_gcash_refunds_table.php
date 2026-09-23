<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_gcash_refunds')) {
            return;
        }

        Schema::create('manual_gcash_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cancellation_approval_request_id')->unique('mgr_request_uq')
                ->constrained('cancellation_approval_requests')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('refund_payment_id')->nullable()->unique('mgr_refund_payment_uq')
                ->constrained('payments')->nullOnDelete();
            $table->string('status', 24)->default('approved');
            $table->decimal('approved_amount', 12, 2);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->string('recipient_name', 120)->nullable();
            $table->text('recipient_account')->nullable();
            $table->string('recipient_account_last_four', 4)->nullable();
            $table->string('gcash_reference', 80)->nullable();
            $table->string('normalized_gcash_reference', 80)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('proof_disk', 40)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_original_name')->nullable();
            $table->string('proof_mime_type', 100)->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->string('proof_sha256', 64)->nullable();
            $table->timestamp('proof_deleted_at')->nullable();
            $table->timestamps();

            $table->unique('normalized_gcash_reference', 'mgr_reference_uq');
            $table->index(['status', 'approved_at'], 'mgr_status_approved_idx');
            $table->index(['booking_id', 'status'], 'mgr_booking_status_idx');
        });
    }

    public function down(): void
    {
    }
};
