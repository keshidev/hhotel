<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        // Get real user IDs from the database — never hardcode
        $admin       = User::where('role', 'admin')->first();
        $receptionist = User::where('role', 'receptionist')->first();

        // Fallback: if only one user exists, use them for all
        $adminId       = $admin?->id ?? 1;
        $receptionistId = $receptionist?->id ?? $adminId;

        Notification::create([
            'user_id'    => $adminId,
            'type'       => 'booking_created',
            'title'      => 'New Booking Created',
            'message'    => 'A new booking (BK001) has been created for check-in on ' . Carbon::now()->addDays(7)->format('M d, Y'),
            'data'       => json_encode(['booking_id' => 1, 'reference_number' => 'BK001', 'guest_name' => 'Roberto Garcia']),
            'read_at'    => Carbon::now()->subDays(4),
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        Notification::create([
            'user_id'    => $receptionistId,
            'type'       => 'payment_received',
            'title'      => 'Payment Verification Needed',
            'message'    => 'A new payment proof has been uploaded for booking BK005 and requires verification.',
            'data'       => json_encode(['booking_id' => 5, 'payment_id' => 6, 'amount' => 2500.00]),
            'read_at'    => null,
            'created_at' => Carbon::now()->subHours(6),
            'updated_at' => Carbon::now()->subHours(6),
        ]);

        Notification::create([
            'user_id'    => $adminId,
            'type'       => 'booking_cancelled',
            'title'      => 'Booking Cancelled',
            'message'    => 'Booking BK008 has been cancelled due to medical emergency. Refund of ₱1,800.00 processed.',
            'data'       => json_encode(['booking_id' => 8, 'refund_amount' => 1800.00, 'cancellation_fee' => 450.00]),
            'read_at'    => Carbon::now()->subDays(2),
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ]);

        Notification::create([
            'user_id'    => $receptionistId,
            'type'       => 'payment_verified',
            'title'      => 'Payment Verified',
            'message'    => 'Payment of ₱12,250.00 for booking BK004 has been verified successfully.',
            'data'       => json_encode(['booking_id' => 4, 'payment_id' => 5, 'verified_by' => 'Receptionist']),
            'read_at'    => Carbon::now()->subDays(14),
            'created_at' => Carbon::now()->subDays(15),
            'updated_at' => Carbon::now()->subDays(15),
        ]);

        Notification::create([
            'user_id'    => $adminId,
            'type'       => 'rebooking_requested',
            'title'      => 'Rebooking Request Pending',
            'message'    => 'A rebooking request has been submitted for booking BK005. Guest wants to change dates.',
            'data'       => json_encode(['rebooking_id' => 2, 'original_booking_id' => 5, 'requested_by' => 'Receptionist']),
            'read_at'    => null,
            'created_at' => Carbon::now()->subHours(12),
            'updated_at' => Carbon::now()->subHours(12),
        ]);

        Notification::create([
            'user_id'    => $receptionistId,
            'type'       => 'upcoming_checkout',
            'title'      => 'Checkout Due Today',
            'message'    => 'Guest Sophia Taylor (Room 101) is scheduled to check out today. Please prepare final billing.',
            'data'       => json_encode(['booking_id' => 10, 'room_number' => '101', 'guest_name' => 'Sophia Taylor']),
            'read_at'    => Carbon::now()->subDays(2),
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ]);

        Notification::create([
            'user_id'    => $adminId,
            'type'       => 'occupancy_alert',
            'title'      => 'Low Occupancy Alert',
            'message'    => 'Current occupancy rate is at 45%. Consider running promotions for the upcoming weeks.',
            'data'       => json_encode(['occupancy_rate' => 45, 'available_rooms' => 11, 'total_rooms' => 16]),
            'read_at'    => null,
            'created_at' => Carbon::now()->subDays(1),
            'updated_at' => Carbon::now()->subDays(1),
        ]);

        Notification::create([
            'user_id'    => $receptionistId,
            'type'       => 'payment_failed',
            'title'      => 'Payment Failed',
            'message'    => 'Credit card payment for booking BK005 was declined. Guest needs to be contacted.',
            'data'       => json_encode(['booking_id' => 5, 'payment_id' => 14, 'reason' => 'Insufficient funds']),
            'read_at'    => null,
            'created_at' => Carbon::now()->subHours(3),
            'updated_at' => Carbon::now()->subHours(3),
        ]);

        Notification::create([
            'user_id'    => $receptionistId,
            'type'       => 'room_status_change',
            'title'      => 'Room Maintenance Completed',
            'message'    => 'Room 204 maintenance has been completed and is now available for booking.',
            'data'       => json_encode(['room_id' => 8, 'room_number' => '204', 'old_status' => 'maintenance', 'new_status' => 'available']),
            'read_at'    => Carbon::now()->subHours(1),
            'created_at' => Carbon::now()->subHours(4),
            'updated_at' => Carbon::now()->subHours(4),
        ]);

        Notification::create([
            'user_id'    => $receptionistId,
            'type'       => 'special_request',
            'title'      => 'Special Request for Anniversary',
            'message'    => 'Guest David Martinez (BK004) has a special request for anniversary room decoration on their third night.',
            'data'       => json_encode(['booking_id' => 4, 'guest_name' => 'David Martinez', 'request' => 'Anniversary on third night']),
            'read_at'    => Carbon::now()->subDays(1),
            'created_at' => Carbon::now()->subDays(1),
            'updated_at' => Carbon::now()->subDays(1),
        ]);

        Notification::create([
            'user_id'    => $adminId,
            'type'       => 'system_update',
            'title'      => 'System Maintenance Schedule',
            'message'    => 'System maintenance is scheduled for next Sunday from 2:00 AM to 4:00 AM. Please inform all staff.',
            'data'       => json_encode(['maintenance_date' => Carbon::now()->addDays(6)->format('Y-m-d'), 'start_time' => '02:00', 'end_time' => '04:00']),
            'read_at'    => null,
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ]);
    }
}