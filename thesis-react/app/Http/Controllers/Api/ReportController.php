<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    /**
     * Generate revenue report.
     */
    public function revenue(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'group_by' => 'sometimes|in:day,week,month',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $groupBy = $request->get('group_by', 'day');
        $startDateTime = $startDate->copy()->startOfDay();
        $endDateTime = $endDate->copy()->endOfDay();

        // Total revenue
        $grossRevenue = Payment::completed()
            ->where('payment_type', '!=', 'refund')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startDateTime, $endDateTime])
            ->sum('amount');

        $totalRefunds = Payment::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startDateTime, $endDateTime])
            ->where(function ($q) {
                $q->where('payment_type', 'refund')
                  ->orWhere('payment_status', 'refunded');
            })
            ->whereNotNull('paid_at')
            ->sum('amount');

        $totalRevenue = max(0, (float) $grossRevenue - (float) $totalRefunds);

        // Revenue breakdown
        $revenueByStatus = Payment::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startDateTime, $endDateTime])
            ->select('payment_status', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_status')
            ->get();

        // Revenue by room type
        $bookingsWithPayments = Booking::with([
                'rooms:id,room_type,room_number',
                'payments' => fn ($q) => $q->completed()
                    ->where('payment_type', '!=', 'refund')
                    ->whereNotNull('paid_at')
                    ->whereBetween('paid_at', [$startDateTime, $endDateTime]),
            ])
            ->whereHas('payments', fn ($q) => $q->completed()
                ->where('payment_type', '!=', 'refund')
                ->whereNotNull('paid_at')
                ->whereBetween('paid_at', [$startDateTime, $endDateTime]))
            ->get();

        $revenueByRoomType = $bookingsWithPayments
            ->flatMap(function ($booking) {
                $amount = (float) $booking->payments->sum('amount');
                $roomCount = max(1, $booking->rooms->count());
                return $booking->rooms->map(fn ($room) => [
                    'room_type' => $room->room_type,
                    'amount' => $amount / $roomCount,
                ]);
            })
            ->groupBy('room_type')
            ->map(function ($rows) {
                return [
                    'count' => $rows->count(),
                    'revenue' => round($rows->sum('amount'), 2),
                ];
            });

        // Revenue over time
        $revenueOverTime = $this->getRevenueOverTime($startDate, $endDate, $groupBy);

        // Top revenue rooms
        $topRooms = $bookingsWithPayments
            ->flatMap(function ($booking) {
                $amount = (float) $booking->payments->sum('amount');
                $roomCount = max(1, $booking->rooms->count());
                return $booking->rooms->map(fn ($room) => [
                    'room_id' => $room->id,
                    'room_number' => $room->room_number,
                    'amount' => $amount / $roomCount,
                ]);
            })
            ->groupBy('room_id')
            ->map(function ($rows) {
                $first = $rows->first();
                return [
                    'room_id' => $first['room_id'],
                    'room_number' => $first['room_number'],
                    'revenue' => round($rows->sum('amount'), 2),
                    'bookings' => $rows->count(),
                ];
            })
            ->sortByDesc('revenue')
            ->take(10)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_revenue' => (float) $totalRevenue,
                    'gross_revenue' => (float) $grossRevenue,
                    'total_refunds' => (float) $totalRefunds,
                    'total_bookings' => Payment::completed()
                        ->where('payment_type', '!=', 'refund')
                        ->whereNotNull('paid_at')
                        ->whereBetween('paid_at', [$startDateTime, $endDateTime])
                        ->distinct('booking_id')
                        ->count('booking_id'),
                    'average_booking_value' => (float) $totalRevenue
                        ? round($totalRevenue / max(1, Payment::completed()
                            ->where('payment_type', '!=', 'refund')
                            ->whereNotNull('paid_at')
                            ->whereBetween('paid_at', [$startDateTime, $endDateTime])
                            ->distinct('booking_id')
                            ->count('booking_id')), 2)
                        : 0,
                ],
                'revenue_by_status' => $revenueByStatus,
                'revenue_by_room_type' => $revenueByRoomType,
                'revenue_over_time' => $revenueOverTime,
                'top_rooms' => $topRooms,
            ],
        ]);
    }

    /**
     * Generate occupancy report.
     */
    public function occupancy(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $roomsBase = DB::table('rooms');
        if (Schema::hasColumn('rooms', 'deleted_at')) {
            $roomsBase->whereNull('deleted_at');
        }

        $startDateStr = $startDate->toDateString();
        $endDateStr = $endDate->toDateString();
        $totalRooms = (clone $roomsBase)
            ->whereNotIn('status', ['maintenance', 'cleaning'])
            ->count();
        $totalDays = $startDate->diffInDays($endDate) + 1;
        $endDatePlus = $endDate->copy()->addDay()->toDateString();

        // Calculate occupancy for each day
        $occupancyData = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $occupied = DB::table('booking_rooms')
                ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
                ->whereIn('bookings.booking_status', ['checked_in', 'checked_out'])
                ->where('bookings.check_in', '<=', $currentDate)
                ->where('bookings.check_out', '>', $currentDate)
                ->distinct('booking_rooms.room_id')
                ->count('booking_rooms.room_id');

            $rate = $totalRooms > 0 ? ($occupied / $totalRooms) * 100 : 0;

            $occupancyData[] = [
                'date' => $currentDate->format('Y-m-d'),
                'occupied_rooms' => $occupied,
                'available_rooms' => max(0, $totalRooms - $occupied),
                'occupancy_rate' => round($rate, 2),
            ];

            $currentDate->addDay();
        }

        // Average occupancy
        $avgOccupancyRate = collect($occupancyData)->avg('occupancy_rate') ?? 0;

        $roomTypes = (clone $roomsBase)
            ->select('room_type')
            ->whereNotNull('room_type')
            ->distinct()
            ->pluck('room_type');

        $overlapSql = "GREATEST(0, DATEDIFF(LEAST(bookings.check_out, DATE_ADD(?, INTERVAL 1 DAY)), GREATEST(bookings.check_in, ?)))";

        $occupiedByType = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->join('rooms', 'booking_rooms.room_id', '=', 'rooms.id')
            ->whereIn('bookings.booking_status', ['checked_in', 'checked_out'])
            ->where('bookings.check_in', '<', $endDatePlus)
            ->where('bookings.check_out', '>', $startDateStr)
            ->selectRaw("rooms.room_type, SUM($overlapSql) as nights", [$endDateStr, $startDateStr])
            ->groupBy('rooms.room_type')
            ->pluck('nights', 'room_type');

        $occupancyByType = $roomTypes->map(function ($type) use ($roomsBase, $occupiedByType, $totalDays) {
            $total = (clone $roomsBase)
                ->where('room_type', $type)
                ->whereNotIn('status', ['maintenance', 'cleaning'])
                ->count();

            $totalRoomNights = $total * $totalDays;
            $occupiedNights = (float) ($occupiedByType[$type] ?? 0);
            $rate = $totalRoomNights > 0 ? ($occupiedNights / $totalRoomNights) * 100 : 0;

            return [
                'total_rooms' => $total,
                'occupancy_rate' => round($rate, 2),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_rooms' => $totalRooms,
                    'average_occupancy_rate' => round($avgOccupancyRate, 2),
                    'peak_occupancy' => $occupancyData
                        ? max(array_column($occupancyData, 'occupancy_rate'))
                        : 0,
                    'lowest_occupancy' => $occupancyData
                        ? min(array_column($occupancyData, 'occupancy_rate'))
                        : 0,
                ],
                'occupancy_over_time' => $occupancyData,
                'occupancy_by_type' => $occupancyByType,
            ],
        ]);
    }

    /**
     * Generate reservation report.
     */
    public function reservations(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'sometimes|in:all,pending,confirmed,checked_in,checked_out,cancelled,no_show,expired',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $status = $request->get('status', 'all');

        $query = Booking::with(['primaryGuest', 'rooms', 'payments'])
            ->where('check_in', '<', $endDate->copy()->addDay()->toDateString())
            ->where('check_out', '>', $startDate->toDateString());

        if ($status !== 'all') {
            if ($status === 'expired') {
                $query->where('booking_status', 'cancelled')
                    ->where('cancelled_reason', 'expired_unpaid');
            } elseif ($status === 'cancelled') {
                $query->where('booking_status', 'cancelled')
                    ->where(function ($q) {
                        $q->whereNull('cancelled_reason')
                          ->orWhere('cancelled_reason', '!=', 'expired_unpaid');
                    });
            } else {
                $query->where('booking_status', $status);
            }
        }

        $bookings = $query->get();

        // Statistics
        $stats = [
            'total_bookings' => $bookings->count(),
            'pending' => $bookings->where('booking_status', 'pending')->count(),
            'confirmed' => $bookings->where('booking_status', 'confirmed')->count(),
            'checked_in' => $bookings->where('booking_status', 'checked_in')->count(),
            'checked_out' => $bookings->where('booking_status', 'checked_out')->count(),
            'no_show' => $bookings->where('booking_status', 'no_show')->count(),
            'cancelled' => $bookings->where('booking_status', 'cancelled')->count(),
            'expired' => $bookings->where('booking_status', 'cancelled')
                ->where('cancelled_reason', 'expired_unpaid')
                ->count(),
            'total_revenue' => (float) $bookings->sum(function ($booking) {
                return $booking->payments->where('payment_status', 'completed')
                    ->whereNotNull('paid_at')
                    ->sum('amount');
            }),
            'total_nights' => $bookings->sum('nights'),
            'average_nights' => round($bookings->avg('nights'), 2),
        ];

        // Bookings by source/channel
        $bookingsByPaymentMethod = $bookings->flatMap(function ($booking) {
            return $booking->payments->pluck('payment_method');
        })->groupBy(fn ($method) => $method)->map->count();

        // Bookings by room type
        $bookingsByRoomType = $bookings->flatMap(function ($booking) {
            return $booking->rooms->pluck('room_type');
        })->groupBy(fn ($type) => $type)->map->count();

        // Daily bookings trend
        $dailyBookings = $bookings->groupBy(function ($booking) {
            return $booking->created_at->format('Y-m-d');
        })->map->count();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ],
                'summary' => $stats,
                'bookings_by_payment_method' => $bookingsByPaymentMethod,
                'bookings_by_room_type' => $bookingsByRoomType,
                'daily_bookings' => $dailyBookings,
                'bookings' => $bookings,
            ],
        ]);
    }

    /**
     * Get revenue over time.
     */
    private function getRevenueOverTime($startDate, $endDate, $groupBy)
    {
        $data = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $nextDate = $currentDate->copy();

            switch ($groupBy) {
                case 'week':
                    $nextDate->addWeek();
                    break;
                case 'month':
                    $nextDate->addMonth();
                    break;
                default:
                    $nextDate->addDay();
            }

            $startRange = $currentDate->copy()->startOfDay();
            $endRange = min($nextDate, $endDate)->copy()->endOfDay();
            $grossRevenue = Payment::completed()
                ->where('payment_type', '!=', 'refund')
                ->whereNotNull('paid_at')
                ->whereBetween('paid_at', [$startRange, $endRange])
                ->sum('amount');
            $refunds = Payment::whereNotNull('paid_at')
                ->whereBetween('paid_at', [$startRange, $endRange])
                ->where(function ($q) {
                    $q->where('payment_type', 'refund')
                      ->orWhere('payment_status', 'refunded');
                })
                ->sum('amount');
            $revenue = max(0, (float) $grossRevenue - (float) $refunds);

            $data[] = [
                'period' => $currentDate->format('Y-m-d'),
                'revenue' => (float) $revenue,
            ];

            $currentDate = $nextDate;
        }

        return $data;
    }

    /**
     * Export report as CSV.
     */
    public function export(Request $request)
    {
        $request->validate([
            'type' => 'required|in:revenue,occupancy,reservations',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        // This would generate CSV/Excel file
        // Implementation depends on your export package (e.g., Laravel Excel)

        return response()->json([
            'success' => true,
            'message' => 'Report export initiated',
        ]);
    }
}
