<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReceptionistDashboardController extends Controller
{
    /**
     * Get receptionist dashboard statistics
     */
    public function index(Request $request)
    {
        $today = Carbon::today();

        // Today's check-ins
        $todayCheckIns = Booking::visibleToStaff()->whereDate('check_in', $today)
            ->whereIn('booking_status', ['confirmed', 'pending'])
            ->count();

        // Today's check-outs
        $todayCheckOuts = Booking::visibleToStaff()->whereDate('check_out', $today)
            ->where('booking_status', 'checked_in')
            ->count();

        // Pending payments
        $pendingPayments = Payment::where('payment_status', 'pending')->count();

        // Pending room assignments
        $pendingRoomAssignments = Booking::visibleToStaff()->where('room_assignment_status', 'pending_assignment')
            ->whereIn('booking_status', ['pending', 'confirmed'])
            ->count();

        // Guests in-house (currently checked in)
        $guestsInHouse = Booking::visibleToStaff()->where('booking_status', 'checked_in')->count();

        // Today's arrivals (detailed) — exclude cancelled bookings
        $todayArrivals = Booking::visibleToStaff()->with(['primaryGuest', 'bookingRooms.room'])
            ->whereDate('check_in', $today)
            ->whereNotIn('booking_status', ['cancelled', 'no_show']) 
            ->orderBy('check_in', 'asc')
            ->get()
            ->map(function ($booking) {
                $room = $booking->bookingRooms->first();
                $roomType = $room?->room?->room_type ?? $room?->requested_room_type ?? 'N/A';
                $roomNumber = $room?->room?->room_number;
                $isPendingLabelAllowed = in_array((string) $booking->booking_status, ['pending', 'confirmed'], true);
                return [
                    'id' => $booking->reference_number,
                    'guest' => $booking->primaryGuest->name ?? 'N/A',
                    'room' => $roomNumber
                        ? ($roomType . ' ' . $roomNumber)
                        : ($isPendingLabelAllowed ? ($roomType . ' (Room Pending)') : $roomType),
                    'time' => Carbon::parse($booking->check_in)->format('H:i'),
                    'status' => $booking->booking_status === 'checked_in' ? 'Checked-In' : 'Expected',
                ];
            });

        $pendingRoomAssignmentsList = Booking::visibleToStaff()->with(['primaryGuest', 'bookingRooms.room'])
            ->where('room_assignment_status', 'pending_assignment')
            ->whereIn('booking_status', ['pending', 'confirmed'])
            ->orderBy('check_in', 'asc')
            ->take(5)
            ->get()
            ->map(function ($booking) {
                $requestedType = $booking->bookingRooms
                    ->map(fn ($line) => $line->room?->room_type ?? $line->requested_room_type)
                    ->filter()
                    ->unique()
                    ->implode(', ');

                return [
                    'id' => $booking->reference_number,
                    'guest' => $booking->primaryGuest->name ?? 'N/A',
                    'room_type' => $requestedType ?: 'N/A',
                    'check_in' => Carbon::parse($booking->check_in)->format('M d, Y'),
                ];
            });

        // Pending payments (detailed)
        $pendingPaymentsList = Payment::with(['booking.primaryGuest'])
            ->where('payment_status', 'pending')
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => 'PAY' . str_pad($payment->id, 3, '0', STR_PAD_LEFT),
                    'guest' => $payment->booking->primaryGuest->name ?? 'N/A',
                    'amount' => '₱' . number_format($payment->amount, 2),
                    'method' => ucfirst(str_replace('_', ' ', $payment->payment_method)),
                    'uploaded' => $payment->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'stats' => [
                'todayCheckIns' => $todayCheckIns,
                'todayCheckOuts' => $todayCheckOuts,
                'pendingPayments' => $pendingPayments,
                'guestsInHouse' => $guestsInHouse,
                'pendingRoomAssignments' => $pendingRoomAssignments,
            ],
            'todayArrivals' => $todayArrivals,
            'pendingPaymentsList' => $pendingPaymentsList,
            'pendingRoomAssignmentsList' => $pendingRoomAssignmentsList,
        ]);
    }

    /**
     * Get quick stats for widgets
     */
    public function getQuickStats()
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        // Calculate trends
        $todayCheckIns = Booking::visibleToStaff()->whereDate('check_in', $today)
            ->whereIn('booking_status', ['confirmed', 'pending'])
            ->count();
        $yesterdayCheckIns = Booking::visibleToStaff()->whereDate('check_in', $yesterday)
            ->whereIn('booking_status', ['confirmed', 'pending', 'checked_in'])
            ->count();
        $checkInChange = $yesterdayCheckIns > 0 
            ? (($todayCheckIns - $yesterdayCheckIns) / $yesterdayCheckIns) * 100 
            : 0;

        return response()->json([
            'todayCheckIns' => [
                'value' => $todayCheckIns,
                'change' => round($checkInChange, 1),
                'trend' => $checkInChange >= 0 ? 'up' : 'down',
            ],
            // Add more stats as needed
        ]);
    }
}
