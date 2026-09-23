<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Seed the default amenities
        $defaults = [
            'WiFi', 'TV', 'Air Conditioning', 'Mini Bar', 'Safe',
            'Coffee Maker', 'Balcony', 'Room Service', 'Bathtub', 'Hair Dryer',
        ];

        foreach ($defaults as $name) {
            DB::table('amenities')->insert([
                'name'       => $name,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('amenities');
    }
};