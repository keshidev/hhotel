<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        // Get real user IDs dynamically — never hardcode
        $admin        = User::where('role', 'admin')->first();
        $receptionist = User::where('role', 'receptionist')->first();

        $adminId       = $admin?->id ?? 1;
        $receptionistId = $receptionist?->id ?? $adminId;

        // User creation log
        ActivityLog::create([
            'user_id'    => $adminId,
            'action'     => 'created',
            'action_activity' => 'User Created',
            'module_page' => 'User Management',
            'model_type' => 'User',
            'model_id'   => $receptionistId,
            'record_affected' => 'User: Receptionist',
            'old_values' => null,
            'new_values' => json_encode(['role' => 'receptionist', 'status' => 'active']),
            'ip_address' => '192.168.1.100',
            'created_at' => Carbon::now()->subMonths(2),
            'updated_at' => Carbon::now()->subMonths(2),
        ]);

        // Booking creation log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'created',
            'action_activity' => 'Booking Created',
            'module_page' => 'Booking Module',
            'model_type' => 'Booking',
            'model_id'   => 1,
            'record_affected' => 'Booking #BK001',
            'old_values' => null,
            'new_values' => json_encode([
                'reference_number' => 'BK001',
                'check_in'         => Carbon::now()->addDays(7)->format('Y-m-d'),
                'check_out'        => Carbon::now()->addDays(10)->format('Y-m-d'),
                'booking_status'   => 'pending',
                'total_amount'     => 4500.00,
            ]),
            'ip_address' => '192.168.1.101',
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        // Booking status update log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'updated',
            'action_activity' => 'Booking Confirmed',
            'module_page' => 'Booking Module',
            'model_type' => 'Booking',
            'model_id'   => 1,
            'record_affected' => 'Booking #BK001',
            'old_values' => json_encode(['booking_status' => 'pending']),
            'new_values' => json_encode(['booking_status' => 'confirmed']),
            'ip_address' => '192.168.1.101',
            'created_at' => Carbon::now()->subDays(5)->addHours(2),
            'updated_at' => Carbon::now()->subDays(5)->addHours(2),
        ]);

        // Room creation log
        ActivityLog::create([
            'user_id'    => $adminId,
            'action'     => 'created',
            'action_activity' => 'Room Created',
            'module_page' => 'Room Management',
            'model_type' => 'Room',
            'model_id'   => 1,
            'record_affected' => 'Room 101 (standard)',
            'old_values' => null,
            'new_values' => json_encode([
                'room_number'     => '101',
                'room_type'       => 'standard',
                'capacity'        => 2,
                'price_per_night' => 1500.00,
                'status'          => 'available',
            ]),
            'ip_address' => '192.168.1.100',
            'created_at' => Carbon::now()->subMonths(3),
            'updated_at' => Carbon::now()->subMonths(3),
        ]);

        // Room status update log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'updated',
            'action_activity' => 'Room Status Updated',
            'module_page' => 'Room Management',
            'model_type' => 'Room',
            'model_id'   => 2,
            'record_affected' => 'Room 102',
            'old_values' => json_encode(['status' => 'available']),
            'new_values' => json_encode(['status' => 'occupied']),
            'ip_address' => '192.168.1.102',
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ]);

        // Payment verification log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'updated',
            'action_activity' => 'Payment Verified',
            'module_page' => 'Payment Module',
            'model_type' => 'Payment',
            'model_id'   => 1,
            'record_affected' => 'Payment #1 — Booking #BK001',
            'old_values' => json_encode(['payment_status' => 'pending', 'verified_by' => null]),
            'new_values' => json_encode(['payment_status' => 'completed', 'verified_by' => $receptionistId]),
            'ip_address' => '192.168.1.101',
            'created_at' => Carbon::now()->subDays(5)->addHours(2),
            'updated_at' => Carbon::now()->subDays(5)->addHours(2),
        ]);

        // Cancellation creation log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'created',
            'action_activity' => 'Booking Cancelled',
            'module_page' => 'Booking Module',
            'model_type' => 'Cancellation',
            'model_id'   => 1,
            'record_affected' => 'Booking #BK008',
            'old_values' => null,
            'new_values' => json_encode([
                'booking_id'       => 8,
                'reason'           => 'Medical emergency',
                'cancellation_fee' => 450.00,
                'refund_amount'    => 1800.00,
            ]),
            'ip_address' => '192.168.1.101',
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ]);

        // Check-in log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'updated',
            'action_activity' => 'Guest Checked In',
            'module_page' => 'Check-In Module',
            'model_type' => 'Booking',
            'model_id'   => 2,
            'record_affected' => 'Booking #BK002',
            'old_values' => json_encode(['booking_status' => 'confirmed']),
            'new_values' => json_encode(['booking_status' => 'checked_in']),
            'ip_address' => '192.168.1.102',
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ]);

        // Check-out log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'updated',
            'action_activity' => 'Guest Checked Out',
            'module_page' => 'Check-Out Module',
            'model_type' => 'Booking',
            'model_id'   => 3,
            'record_affected' => 'Booking #BK003',
            'old_values' => json_encode(['booking_status' => 'checked_in']),
            'new_values' => json_encode(['booking_status' => 'checked_out']),
            'ip_address' => '192.168.1.101',
            'created_at' => Carbon::now()->subDays(7),
            'updated_at' => Carbon::now()->subDays(7),
        ]);

        // Room status change to maintenance
        ActivityLog::create([
            'user_id'    => $adminId,
            'action'     => 'updated',
            'action_activity' => 'Room Status Updated',
            'module_page' => 'Room Management',
            'model_type' => 'Room',
            'model_id'   => 8,
            'record_affected' => 'Room 108',
            'old_values' => json_encode(['status' => 'available']),
            'new_values' => json_encode(['status' => 'maintenance']),
            'ip_address' => '192.168.1.100',
            'created_at' => Carbon::now()->subDays(10),
            'updated_at' => Carbon::now()->subDays(10),
        ]);

        // Room price update log
        ActivityLog::create([
            'user_id'    => $adminId,
            'action'     => 'updated',
            'action_activity' => 'Room Updated',
            'module_page' => 'Room Management',
            'model_type' => 'Room',
            'model_id'   => 5,
            'record_affected' => 'Room 105',
            'old_values' => json_encode(['price_per_night' => 2300.00]),
            'new_values' => json_encode(['price_per_night' => 2500.00]),
            'ip_address' => '192.168.1.100',
            'created_at' => Carbon::now()->subMonths(1),
            'updated_at' => Carbon::now()->subMonths(1),
        ]);

        // Room cleaning status log
        ActivityLog::create([
            'user_id'    => $receptionistId,
            'action'     => 'updated',
            'action_activity' => 'Room Status Updated',
            'module_page' => 'Room Management',
            'model_type' => 'Room',
            'model_id'   => 3,
            'record_affected' => 'Room 103',
            'old_values' => json_encode(['status' => 'occupied']),
            'new_values' => json_encode(['status' => 'cleaning']),
            'ip_address' => '192.168.1.102',
            'created_at' => Carbon::now()->subHours(6),
            'updated_at' => Carbon::now()->subHours(6),
        ]);

        // User status deactivation log
        ActivityLog::create([
            'user_id'    => $adminId,
            'action'     => 'updated',
            'action_activity' => 'User Status Changed',
            'module_page' => 'User Management',
            'model_type' => 'User',
            'model_id'   => $receptionistId,
            'record_affected' => 'User: Receptionist',
            'old_values' => json_encode(['status' => 'active']),
            'new_values' => json_encode(['status' => 'inactive']),
            'ip_address' => '192.168.1.100',
            'created_at' => Carbon::now()->subDays(30),
            'updated_at' => Carbon::now()->subDays(30),
        ]);

        // Room deletion log
        ActivityLog::create([
            'user_id'    => $adminId,
            'action'     => 'deleted',
            'action_activity' => 'Room Deleted',
            'module_page' => 'Room Management',
            'model_type' => 'Room',
            'model_id'   => 99,
            'record_affected' => 'Room 999 (standard)',
            'old_values' => json_encode(['room_number' => '999', 'room_type' => 'standard', 'status' => 'maintenance']),
            'new_values' => null,
            'ip_address' => '192.168.1.100',
            'created_at' => Carbon::now()->subMonths(1),
            'updated_at' => Carbon::now()->subMonths(1),
        ]);
    }
}