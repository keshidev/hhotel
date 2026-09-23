<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BookingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Wrap in try-catch to see errors
        try {
            // Verify prerequisites
            $userCount = DB::table('users')->count();
            $roomCount = DB::table('rooms')->count();
            
            if ($userCount === 0) {
                $this->command->error('ERROR: No users found! Run UserSeeder first.');
                return;
            }
            
            if ($roomCount === 0) {
                $this->command->error('ERROR: No rooms found! Run RoomSeeder first.');
                return;
            }
            
            $this->command->info("Users found: {$userCount}");
            $this->command->info("Rooms found: {$roomCount}");
            
            // Get actual user IDs
            $users = DB::table('users')->pluck('id')->toArray();
            $firstUserId = $users[0] ?? 1;
            $secondUserId = $users[1] ?? $firstUserId;
            $thirdUserId = $users[2] ?? $firstUserId;
            
            // Get actual room IDs
            $rooms = DB::table('rooms')->pluck('id')->toArray();
            
            $this->command->info("Starting to seed bookings...");

            // Booking 1 - Confirmed booking for next week
            $booking1 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->addDays(7)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(10)->format('Y-m-d'),
                'number_of_guests' => 2,
                'booking_status' => 'confirmed',
                'special_requests' => 'Late check-in requested, arriving around 10 PM',
                'total_amount' => 4500.00,
                'created_by' => $secondUserId,
                'created_at' => Carbon::now()->subDays(5),
            ]);
            $this->command->info("✓ Created Booking 1 (ID: {$booking1->id})");

            BookingGuest::create([
                'booking_id' => $booking1->id,
                'name' => 'Roberto Garcia',
                'email' => 'roberto.garcia@email.com',
                'phone' => '+639171234001',
                'special_requests' => 'Non-smoking room please',
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking1->id,
                'room_id' => $rooms[0] ?? 1,
                'price_per_night' => 1500.00,
                'nights' => 3,
                'subtotal' => 4500.00,
            ]);

            // Booking 2 - Currently checked in
            $booking2 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->subDays(2)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(3)->format('Y-m-d'),
                'number_of_guests' => 2,
                'booking_status' => 'checked_in',
                'special_requests' => null,
                'total_amount' => 12500.00,
                'created_by' => $thirdUserId,
                'created_at' => Carbon::now()->subDays(10),
            ]);
            $this->command->info("✓ Created Booking 2 (ID: {$booking2->id})");

            BookingGuest::create([
                'booking_id' => $booking2->id,
                'name' => 'Sarah Johnson',
                'email' => 'sarah.j@email.com',
                'phone' => '+639181234002',
                'special_requests' => null,
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking2->id,
                'room_id' => $rooms[1] ?? 2,
                'price_per_night' => 2500.00,
                'nights' => 5,
                'subtotal' => 12500.00,
            ]);

            // Booking 3 - Checked out
            $booking3 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->subDays(10)->format('Y-m-d'),
                'check_out' => Carbon::now()->subDays(7)->format('Y-m-d'),
                'number_of_guests' => 3,
                'booking_status' => 'checked_out',
                'special_requests' => 'Extra pillows and blankets',
                'total_amount' => 12000.00,
                'created_by' => $secondUserId,
                'created_at' => Carbon::now()->subDays(25),
            ]);
            $this->command->info("✓ Created Booking 3 (ID: {$booking3->id})");

            BookingGuest::create([
                'booking_id' => $booking3->id,
                'name' => 'Michael Chen',
                'email' => 'michael.chen@email.com',
                'phone' => '+639191234003',
                'special_requests' => null,
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking3->id,
                'room_id' => $rooms[9] ?? 10,
                'price_per_night' => 4000.00,
                'nights' => 3,
                'subtotal' => 12000.00,
            ]);

            // Booking 4 - Family booking
            $booking4 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->subDays(1)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(6)->format('Y-m-d'),
                'number_of_guests' => 4,
                'booking_status' => 'checked_in',
                'special_requests' => 'Celebrating anniversary, need help with room decoration',
                'total_amount' => 24500.00,
                'created_by' => $firstUserId,
                'created_at' => Carbon::now()->subDays(15),
            ]);
            $this->command->info("✓ Created Booking 4 (ID: {$booking4->id})");

            BookingGuest::create([
                'booking_id' => $booking4->id,
                'name' => 'David Martinez',
                'email' => 'david.martinez@email.com',
                'phone' => '+639201234004',
                'special_requests' => 'Anniversary on third night',
                'is_primary' => true,
            ]);

            BookingGuest::create([
                'booking_id' => $booking4->id,
                'name' => 'Lisa Martinez',
                'email' => 'lisa.martinez@email.com',
                'phone' => '+639201234005',
                'special_requests' => null,
                'is_primary' => false,
            ]);

            BookingRoom::create([
                'booking_id' => $booking4->id,
                'room_id' => $rooms[14] ?? 15,
                'price_per_night' => 3500.00,
                'nights' => 7,
                'subtotal' => 24500.00,
            ]);

            // Booking 5 - Pending
            $booking5 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->addDays(14)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(16)->format('Y-m-d'),
                'number_of_guests' => 2,
                'booking_status' => 'pending',
                'special_requests' => 'Honeymoon suite if available',
                'total_amount' => 5000.00,
                'created_by' => $secondUserId,
                'created_at' => Carbon::now()->subHours(6),
            ]);
            $this->command->info("✓ Created Booking 5 (ID: {$booking5->id})");

            BookingGuest::create([
                'booking_id' => $booking5->id,
                'name' => 'James Wilson',
                'email' => 'james.wilson@email.com',
                'phone' => '+639211234006',
                'special_requests' => null,
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking5->id,
                'room_id' => $rooms[4] ?? 5,
                'price_per_night' => 2500.00,
                'nights' => 2,
                'subtotal' => 5000.00,
            ]);

            // Booking 6 - Suite booking
            $booking6 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->subDays(3)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(2)->format('Y-m-d'),
                'number_of_guests' => 2,
                'booking_status' => 'checked_in',
                'special_requests' => 'Business traveler, need early breakfast',
                'total_amount' => 20000.00,
                'created_by' => $thirdUserId,
                'created_at' => Carbon::now()->subDays(20),
            ]);
            $this->command->info("✓ Created Booking 6 (ID: {$booking6->id})");

            BookingGuest::create([
                'booking_id' => $booking6->id,
                'name' => 'Patricia Lee',
                'email' => 'patricia.lee@corporate.com',
                'phone' => '+639221234007',
                'special_requests' => 'Early breakfast at 6 AM',
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking6->id,
                'room_id' => $rooms[10] ?? 11,
                'price_per_night' => 4000.00,
                'nights' => 5,
                'subtotal' => 20000.00,
            ]);

            // Booking 7 - Future booking
            $booking7 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->addDays(30)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(33)->format('Y-m-d'),
                'number_of_guests' => 2,
                'booking_status' => 'confirmed',
                'special_requests' => null,
                'total_amount' => 7500.00,
                'created_by' => $firstUserId,
                'created_at' => Carbon::now()->subDays(2),
            ]);
            $this->command->info("✓ Created Booking 7 (ID: {$booking7->id})");

            BookingGuest::create([
                'booking_id' => $booking7->id,
                'name' => 'Thomas Anderson',
                'email' => 'thomas.a@email.com',
                'phone' => '+639231234008',
                'special_requests' => null,
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking7->id,
                'room_id' => $rooms[5] ?? 6,
                'price_per_night' => 2500.00,
                'nights' => 3,
                'subtotal' => 7500.00,
            ]);

            // Booking 8 - Cancelled
            $booking8 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->addDays(5)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(8)->format('Y-m-d'),
                'number_of_guests' => 2,
                'booking_status' => 'cancelled',
                'special_requests' => null,
                'total_amount' => 4500.00,
                'created_by' => $secondUserId,
                'created_at' => Carbon::now()->subDays(8),
            ]);
            $this->command->info("✓ Created Booking 8 (ID: {$booking8->id})");

            BookingGuest::create([
                'booking_id' => $booking8->id,
                'name' => 'Emma Brown',
                'email' => 'emma.brown@email.com',
                'phone' => '+639241234009',
                'special_requests' => null,
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking8->id,
                'room_id' => $rooms[3] ?? 4,
                'price_per_night' => 1500.00,
                'nights' => 3,
                'subtotal' => 4500.00,
            ]);

            // Booking 9 - Multiple rooms
            $booking9 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->addDays(20)->format('Y-m-d'),
                'check_out' => Carbon::now()->addDays(23)->format('Y-m-d'),
                'number_of_guests' => 6,
                'booking_status' => 'confirmed',
                'special_requests' => 'Group booking for corporate retreat',
                'total_amount' => 16500.00,
                'created_by' => $thirdUserId,
                'created_at' => Carbon::now()->subDays(12),
            ]);
            $this->command->info("✓ Created Booking 9 (ID: {$booking9->id})");

            BookingGuest::create([
                'booking_id' => $booking9->id,
                'name' => 'Christopher Davis',
                'email' => 'chris.davis@company.com',
                'phone' => '+639251234010',
                'special_requests' => 'Rooms should be close to each other',
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking9->id,
                'room_id' => $rooms[6] ?? 7,
                'price_per_night' => 2500.00,
                'nights' => 3,
                'subtotal' => 7500.00,
            ]);

            BookingRoom::create([
                'booking_id' => $booking9->id,
                'room_id' => $rooms[9] ?? 10,
                'price_per_night' => 3000.00,
                'nights' => 3,
                'subtotal' => 9000.00,
            ]);

            // Booking 10 - Recent checkout
            $booking10 = Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'check_in' => Carbon::now()->subDays(5)->format('Y-m-d'),
                'check_out' => Carbon::now()->subDays(2)->format('Y-m-d'),
                'number_of_guests' => 1,
                'booking_status' => 'checked_out',
                'special_requests' => 'Quiet room please',
                'total_amount' => 4500.00,
                'created_by' => $firstUserId,
                'created_at' => Carbon::now()->subDays(18),
            ]);
            $this->command->info("✓ Created Booking 10 (ID: {$booking10->id})");

            BookingGuest::create([
                'booking_id' => $booking10->id,
                'name' => 'Sophia Taylor',
                'email' => 'sophia.taylor@email.com',
                'phone' => '+639261234011',
                'special_requests' => null,
                'is_primary' => true,
            ]);

            BookingRoom::create([
                'booking_id' => $booking10->id,
                'room_id' => $rooms[0] ?? 1,
                'price_per_night' => 1500.00,
                'nights' => 3,
                'subtotal' => 4500.00,
            ]);

            $this->command->info("✓ Successfully created 10 bookings with guests and room assignments!");
            
        } catch (\Exception $e) {
            $this->command->error("ERROR: " . $e->getMessage());
            $this->command->error("Line: " . $e->getLine());
            $this->command->error("File: " . $e->getFile());
            throw $e;
        }
    }
}