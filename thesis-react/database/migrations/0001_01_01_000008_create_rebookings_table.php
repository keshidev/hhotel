<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('new_booking_id')->nullable()->constrained('bookings')->onDelete('set null');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebookings');
    }
};