<?php

namespace Database\Seeders;

use App\Models\Rebooking;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class RebookingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a new booking for the rebooking demonstration
        $newBooking = Booking::create([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'check_in' => Carbon::now()->addDays(12),
            'check_out' => Carbon::now()->addDays(15),
            'number_of_guests' => 2,
            'booking_status' => 'confirmed',
            'special_requests' => 'Rebooked due to date change',
            'total_amount' => 4500.00,
            'created_by' => 2,
            'created_at' => Carbon::now()->subDays(1),
        ]);

        BookingGuest::create([
            'booking_id' => $newBooking->id,
            'name' => 'Emma Brown',
            'email' => 'emma.brown@email.com',
            'phone' => '+639241234009',
            'special_requests' => null,
            'is_primary' => true,
        ]);

        BookingRoom::create([
            'booking_id' => $newBooking->id,
            'room_id' => 4,
            'price_per_night' => 1500.00,
            'nights' => 3,
            'subtotal' => 4500.00,
        ]);

        // Approved rebooking - from cancelled Booking 8 to new booking
        Rebooking::create([
            'original_booking_id' => 8,
            'new_booking_id' => $newBooking->id,
            'reason' => 'Guest recovered from medical emergency and wants to reschedule for a later date.',
            'requested_by' => 1, // Admin
            'approved_by' => 1,
            'status' => 'approved',
            'approved_at' => Carbon::now()->subDays(1),
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Pending rebooking request (no new booking created yet)
        Rebooking::create([
            'original_booking_id' => 5,
            'new_booking_id' => null,
            'reason' => 'Guest wants to change dates from 2 weeks to 3 weeks from now. Awaiting confirmation of new dates.',
            'requested_by' => 2,
            'approved_by' => null,
            'status' => 'pending',
            'approved_at' => null,
            'created_at' => Carbon::now()->subHours(12),
        ]);

        // Rejected rebooking request
        Rebooking::create([
            'original_booking_id' => 7,
            'new_booking_id' => null,
            'reason' => 'Requested to move booking to peak season dates. Unable to accommodate due to full occupancy.',
            'requested_by' => 4,
            'approved_by' => 1,
            'status' => 'rejected',
            'approved_at' => null,
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(4),
        ]);
    }
}