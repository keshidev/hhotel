<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->onDelete('cascade');
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');

            // Guest identifier — email used at booking time (works for both
            // logged-in users and guest checkouts).
            $table->string('guest_email');

            $table->decimal('discount_amount', 10, 2); // actual amount saved
            $table->timestamp('used_at');
            $table->timestamps();

            // One usage per booking
            $table->unique(['promo_code_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_usages');
    }
};