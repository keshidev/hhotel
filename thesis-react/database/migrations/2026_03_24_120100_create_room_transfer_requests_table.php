<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('booking_room_id')->nullable()->constrained('booking_rooms')->nullOnDelete();
            $table->foreignId('current_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('target_room_id')->constrained('rooms')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', [
                'pending_approval',
                'approved',
                'rejected',
                'completed',
            ])->default('pending_approval');

            $table->text('decision_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status'], 'rtr_booking_status_idx');
            $table->index(['target_room_id', 'status'], 'rtr_target_room_status_idx');
            $table->index(['requested_by', 'created_at'], 'rtr_requested_by_created_at_idx');
            $table->index(['status', 'created_at'], 'rtr_status_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_transfer_requests');
    }
};

