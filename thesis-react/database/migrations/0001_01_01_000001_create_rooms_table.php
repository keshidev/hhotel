<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number')->unique();
            $table->enum('room_type', [
                'executive_suite',
                'family',
                'deluxe',
                'superior_twin',
                'superior_queen',
                'premier',
            ]);
            $table->integer('capacity');
            $table->decimal('price_per_night', 10, 2);

            // Day tour rate (12-hour stay, walk-in only).
            // If null, system falls back to 65% of price_per_night.
            $table->decimal('price_day_tour', 10, 2)->nullable();

            $table->integer('floor')->nullable();
            $table->enum('status', ['available', 'occupied', 'maintenance', 'cleaning'])->default('available');
            $table->text('description')->nullable();
            $table->json('amenities')->nullable();
            $table->json('images')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};