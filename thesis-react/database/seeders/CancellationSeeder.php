<?php

namespace Database\Seeders;

use App\Models\Cancellation;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class CancellationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cancellation for Booking 8
        Cancellation::create([
            'booking_id' => 8,
            'reason' => 'Guest had a medical emergency and cannot travel. Requested full refund consideration.',
            'cancelled_by' => 2,
            'cancellation_fee' => 450.00, // 20% of total booking (4500 * 0.10)
            'refund_amount' => 1800.00, // Original downpayment (2250) minus cancellation fee (450)
            'cancelled_at' => Carbon::now()->subDays(3),
            'created_at' => Carbon::now()->subDays(3),
        ]);

        // Another example cancellation (for demonstration)
        // This would need a corresponding booking, but showing structure
        // Cancellation::create([
        //     'booking_id' => 11,
        //     'reason' => 'Change in travel plans due to work commitments.',
        //     'cancelled_by' => 3,
        //     'cancellation_fee' => 750.00,
        //     'refund_amount' => 2250.00,
        //     'cancelled_at' => Carbon::now()->subDays(15),
        //     'created_at' => Carbon::now()->subDays(15),
        // ]);
    }
}