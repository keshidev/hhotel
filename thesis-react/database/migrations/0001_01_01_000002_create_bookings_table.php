<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->date('check_in');
            $table->date('check_out');
            $table->integer('number_of_guests');
            $table->enum('booking_status', ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'])->default('pending');
            $table->text('special_requests')->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            // 'online'   = guest booked through the website
            // 'walk_in'  = receptionist created at the front desk
            $table->enum('booking_source', ['online', 'walk_in'])->default('online');

            // True when the booking is a day tour (12-hour stay, walk-in only)
            $table->boolean('is_day_tour')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};