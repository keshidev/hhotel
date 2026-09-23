<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('early_check_in_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->text('reason');
            $table->enum('status', [
                'pending_approval',
                'approved',
                'rejected',
                'consumed',
                'expired',
            ])->default('pending_approval');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->timestamps();

            $table->unique(['booking_id', 'active_slot'], 'eci_booking_active_uq');
            $table->index(['status', 'created_at'], 'eci_status_created_idx');
            $table->index(['requested_by', 'created_at'], 'eci_requester_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('early_check_in_requests');
    }
};
