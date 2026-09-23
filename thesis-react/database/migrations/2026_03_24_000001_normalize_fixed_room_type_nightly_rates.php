<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Align room master data with fixed nightly pricing policy for
     * currently supported mapped room types.
     */
    public function up(): void
    {
        $rates = [
            'deluxe' => 1500.00,
            'superior_queen' => 1000.00,
            'superior_twin' => 1000.00,
            'premier' => 2000.00,
            'executive_suite' => 3000.00,
        ];

        foreach ($rates as $roomType => $nightlyRate) {
            DB::table('rooms')
                ->where('room_type', $roomType)
                ->update([
                    'price_per_night' => $nightlyRate,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Historical room rates cannot be reconstructed safely here.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};

