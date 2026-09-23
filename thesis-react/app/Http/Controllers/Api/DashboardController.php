<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics.
     */
    public function index(Request $request)
    {
        $period = $request->get('period', 'month'); // day, week, month, year

        $stats = [
            'overview' => $this->getOverviewStats(),
            'revenue' => $this->getRevenueStats($period),
            'occupancy' => $this->getOccupancyStats(),
            'recent_bookings' => $this->getRecentBookings(),
            'room_status' => $this->getRoomStatusStats(),
            'charts' => [
                'revenue' => $this->getRevenueChart($period),
                'bookings' => $this->getBookingsChart($period),
                'occupancy' => $this->getOccupancyChart($period),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get overview statistics.
     */
    private function getOverviewStats()
    {
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'total_revenue' => [
                'value' => Booking::where('payment_status', 'paid')->sum('total'),
                'change' => $this->calculateChange(
                    Booking::where('payment_status', 'paid')
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->sum('total'),
                    Booking::where('payment_status', 'paid')
                        ->whereMonth('created_at', Carbon::now()->subMonth()->month)
                        ->sum('total')
                ),
            ],
            'total_bookings' => [
                'value' => Booking::count(),
                'change' => $this->calculateChange(
                    Booking::whereMonth('created_at', Carbon::now()->month)->count(),
                    Booking::whereMonth('created_at', Carbon::now()->subMonth()->month)->count()
                ),
            ],
            'total_rooms' => Room::count(),
            'available_rooms' => Room::where('status', 'available')->count(),
            'occupied_rooms' => Room::where('status', 'occupied')->count(),
            'total_guests' => User::where('role', 'guest')->count(),
            'check_ins_today' => Booking::where('check_in', $today)->count(),
            'check_outs_today' => Booking::where('check_out', $today)->count(),
            'pending_bookings' => Booking::where('status', 'pending')->count(),
        ];
    }

    /**
     * Get revenue statistics.
     */
    private function getRevenueStats($period)
    {
        $query = Booking::where('payment_status', 'paid');

        switch ($period) {
            case 'day':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'week':
                $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereMonth('created_at', Carbon::now()->month);
                break;
            case 'year':
                $query->whereYear('created_at', Carbon::now()->year);
                break;
        }

        return [
            'total' => $query->sum('total'),
            'count' => $query->count(),
            'average' => $query->avg('total'),
        ];
    }

    /**
     * Get occupancy statistics.
     */
    private function getOccupancyStats()
    {
        $totalRooms = Room::count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;

        return [
            'total_rooms' => $totalRooms,
            'occupied_rooms' => $occupiedRooms,
            'available_rooms' => $totalRooms - $occupiedRooms,
            'occupancy_rate' => round($occupancyRate, 2),
        ];
    }

    /**
     * Get recent bookings.
     */
    private function getRecentBookings()
    {
        return Booking::with(['user', 'room'])
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * Get room status statistics.
     */
    private function getRoomStatusStats()
    {
        return [
            'available' => Room::where('status', 'available')->count(),
            'occupied' => Room::where('status', 'occupied')->count(),
            'maintenance' => Room::where('status', 'maintenance')->count(),
        ];
    }

    /**
     * Get revenue chart data.
     */
    private function getRevenueChart($period)
    {
        $dates = $this->getDateRange($period);
        $data = [];

        foreach ($dates as $date) {
            $revenue = Booking::where('payment_status', 'paid')
                ->whereDate('created_at', $date)
                ->sum('total');

            $data[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format($period === 'month' ? 'M d' : 'M d, Y'),
                'value' => (float) $revenue,
            ];
        }

        return $data;
    }

    /**
     * Get bookings chart data.
     */
    private function getBookingsChart($period)
    {
        $dates = $this->getDateRange($period);
        $data = [];

        foreach ($dates as $date) {
            $count = Booking::whereDate('created_at', $date)->count();

            $data[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format($period === 'month' ? 'M d' : 'M d, Y'),
                'value' => $count,
            ];
        }

        return $data;
    }

    /**
     * Get occupancy chart data.
     */
    private function getOccupancyChart($period)
    {
        $dates = $this->getDateRange($period);
        $totalRooms = Room::count();
        $data = [];

        foreach ($dates as $date) {
            $occupied = Booking::where('check_in', '<=', $date)
                ->where('check_out', '>=', $date)
                ->where('status', '!=', 'cancelled')
                ->count();

            $rate = $totalRooms > 0 ? ($occupied / $totalRooms) * 100 : 0;

            $data[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format($period === 'month' ? 'M d' : 'M d, Y'),
                'value' => round($rate, 2),
            ];
        }

        return $data;
    }

    /**
     * Get date range based on period.
     */
    private function getDateRange($period)
    {
        $dates = [];

        switch ($period) {
            case 'day':
                for ($i = 23; $i >= 0; $i--) {
                    $dates[] = Carbon::now()->subHours($i);
                }
                break;
            case 'week':
                for ($i = 6; $i >= 0; $i--) {
                    $dates[] = Carbon::now()->subDays($i);
                }
                break;
            case 'month':
                for ($i = 29; $i >= 0; $i--) {
                    $dates[] = Carbon::now()->subDays($i);
                }
                break;
            case 'year':
                for ($i = 11; $i >= 0; $i--) {
                    $dates[] = Carbon::now()->subMonths($i)->startOfMonth();
                }
                break;
        }

        return $dates;
    }

    /**
     * Calculate percentage change.
     */
    private function calculateChange($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
