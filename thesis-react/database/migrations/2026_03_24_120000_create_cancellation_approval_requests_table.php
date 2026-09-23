<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->text('reason');
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->string('refund_method', 50)->nullable();
            $table->text('request_note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', [
                'pending_approval',
                'approved',
                'rejected',
                'refund_pending',
                'refunded',
                'cancelled',
            ])->default('pending_approval');

            $table->text('decision_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamp('refund_processed_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->foreignId('cancellation_id')->nullable()->constrained('cancellations')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'status'], 'car_booking_status_idx');
            $table->index(['requested_by', 'created_at'], 'car_requested_by_created_at_idx');
            $table->index(['status', 'created_at'], 'car_status_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_approval_requests');
    }
};
