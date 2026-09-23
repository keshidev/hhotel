<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class OccupancyReportController extends Controller
{
    /**
     * GET /api/admin/reports/occupancy
     * Query params: start_date, end_date
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date',   now()->toDateString());

        $startDateTime = $startDate . ' 00:00:00';
        $endDateTime   = $endDate   . ' 23:59:59';

        // ── TOTAL ROOMS ───────────────────────────────────────────────────
        // Check soft-delete column ONCE — Schema::hasColumn is an introspection
        // query and must not be called inside loops or repeated closures.
        $hasSoftDelete = Schema::hasColumn('rooms', 'deleted_at');

        $roomsBase = DB::table('rooms');
        if ($hasSoftDelete) {
            $roomsBase->whereNull('deleted_at');
        }

        $totalRooms        = (clone $roomsBase)->count();
        $availableRooms    = (clone $roomsBase)->whereNotIn('status', ['maintenance', 'cleaning'])->count();
        $readyRooms        = (clone $roomsBase)->where('status', 'available')->count();
        $occupiedRooms     = (clone $roomsBase)->where('status', 'occupied')->count();
        $cleaningRooms     = (clone $roomsBase)->where('status', 'cleaning')->count();
        $maintenanceRooms  = (clone $roomsBase)->where('status', 'maintenance')->count();

        $totalDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $endDatePlus = Carbon::parse($endDate)->addDay()->toDateString();

        // ── OCCUPANCY RATE (room-nights within date range) ──
        $overlapSql = "GREATEST(0, DATEDIFF(LEAST(bookings.check_out, DATE_ADD(?, INTERVAL 1 DAY)), GREATEST(bookings.check_in, ?)))";

        $occupiedRoomNights = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->whereIn('bookings.booking_status', ['checked_in', 'checked_out'])
            ->where('bookings.check_in', '<', $endDatePlus)
            ->where('bookings.check_out', '>', $startDate)
            ->selectRaw("SUM($overlapSql) as nights", [$endDate, $startDate])
            ->value('nights');

        $availableRoomNights = $availableRooms * $totalDays;

        $occupancyRate = $availableRoomNights > 0
            ? round(((float) $occupiedRoomNights / $availableRoomNights) * 100, 1)
            : 0;

        $occupancyIntervals = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->whereNotNull('booking_rooms.room_id')
            ->whereIn('bookings.booking_status', ['checked_in', 'checked_out'])
            ->where('bookings.check_in', '<', $endDatePlus)
            ->where('bookings.check_out', '>', $startDate)
            ->get(['bookings.check_in', 'bookings.check_out']);

        $dailyOccupancy = collect();
        $chartDate = Carbon::parse($startDate)->startOfDay();
        $chartEndDate = Carbon::parse($endDate)->startOfDay();

        while ($chartDate->lte($chartEndDate)) {
            $occupied = $occupancyIntervals->filter(function ($interval) use ($chartDate) {
                return Carbon::parse($interval->check_in)->startOfDay()->lte($chartDate)
                    && Carbon::parse($interval->check_out)->startOfDay()->gt($chartDate);
            })->count();

            $dailyOccupancy->push([
                'date' => $chartDate->toDateString(),
                'occupied_rooms' => $occupied,
                'available_rooms' => $availableRooms,
                'rate' => $availableRooms > 0
                    ? round(min(100, ($occupied / $availableRooms) * 100), 1)
                    : 0,
            ]);

            $chartDate->addDay();
        }

        // ── PREVIOUS PERIOD (for % change badge) ─────────────────────────
        $diffDays  = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $prevEnd   = Carbon::parse($startDate)->subDay()->toDateString();
        $prevStart = Carbon::parse($prevEnd)->subDays($diffDays - 1)->toDateString();

        $prevEndPlus = Carbon::parse($prevEnd)->addDay()->toDateString();

        $prevOccupiedRoomNights = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->whereIn('bookings.booking_status', ['checked_in', 'checked_out'])
            ->where('bookings.check_in', '<', $prevEndPlus)
            ->where('bookings.check_out', '>', $prevStart)
            ->selectRaw("SUM($overlapSql) as nights", [$prevEnd, $prevStart])
            ->value('nights');

        // Previous period uses its own day count for the denominator.
        // Using $totalDays (current period) here would produce an incorrect
        // rate whenever the two periods have different lengths.
        $prevDiffDays = Carbon::parse($prevStart)->diffInDays(Carbon::parse($prevEnd)) + 1;
        $prevAvailableRoomNights = $availableRooms * $prevDiffDays;

        $prevOccupancyRate = $prevAvailableRoomNights > 0
            ? round(((float) $prevOccupiedRoomNights / $prevAvailableRoomNights) * 100, 1)
            : 0;

        $occupancyChange = $prevOccupancyRate > 0
            ? round($occupancyRate - $prevOccupancyRate, 1)
            : null;

        // ── OCCUPANCY BY ROOM TYPE ────────────────────────────────────────
        $roomTypes = (clone $roomsBase)
            ->select('room_type')
            ->whereNotNull('room_type')
            ->distinct()
            ->pluck('room_type');

        $occupiedByType = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->join('rooms', 'booking_rooms.room_id', '=', 'rooms.id')
            ->whereIn('bookings.booking_status', ['checked_in', 'checked_out'])
            ->where('bookings.check_in', '<', $endDatePlus)
            ->where('bookings.check_out', '>', $startDate)
            ->selectRaw("rooms.room_type, SUM($overlapSql) as nights", [$endDate, $startDate])
            ->groupBy('rooms.room_type')
            ->pluck('nights', 'room_type');

        // Single GROUP BY query replaces 2 COUNT queries per room type (was O(2N) queries).
        $roomCountsRaw = DB::table('rooms')
            ->when($hasSoftDelete, fn ($q) => $q->whereNull('deleted_at'))
            ->whereNotNull('room_type')
            ->selectRaw("
                room_type,
                COUNT(*) as total,
                SUM(CASE WHEN status NOT IN ('maintenance', 'cleaning') THEN 1 ELSE 0 END) as available
            ")
            ->groupBy('room_type')
            ->get()
            ->keyBy('room_type');

        $roomsByType = $roomTypes->map(function ($type) use ($roomCountsRaw, $occupiedByType, $totalDays) {
            $counts = $roomCountsRaw->get($type);

            $total     = $counts ? (int) $counts->total     : 0;
            $available = $counts ? (int) $counts->available : 0;

            $occupiedNights  = (float) ($occupiedByType[$type] ?? 0);
            $availableNights = $available * $totalDays;
            $rate = $availableNights > 0 ? round(($occupiedNights / $availableNights) * 100, 1) : 0;

            return [
                'type'      => ucfirst($type),
                'total'     => $total,
                'occupied'  => $occupiedNights,
                'available' => max(0, $availableNights - $occupiedNights),
                'rate'      => $rate,
            ];
        })->values();

        // ── ROOM STATUS BREAKDOWN ─────────────────────────────────────────
        $statusBreakdown = [
            ['status' => 'Available',   'count' => $readyRooms,        'color' => '#10b981'],
            ['status' => 'Occupied',    'count' => $occupiedRooms,     'color' => '#3b82f6'],
            ['status' => 'Cleaning',    'count' => $cleaningRooms,     'color' => '#f59e0b'],
            ['status' => 'Maintenance', 'count' => $maintenanceRooms,  'color' => '#ef4444'],
        ];

        // ── Rooms that still require housekeeping preparation ───────────────────
        $roomsForPreparation = (clone $roomsBase)
            ->whereIn('status', ['cleaning', 'maintenance'])
            ->orderByRaw("FIELD(status, 'cleaning', 'maintenance')")
            ->orderBy('room_number')
            ->get()
            ->map(function ($room) {
                $lastCheckout = DB::table('booking_rooms')
                    ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
                    ->where('booking_rooms.room_id', $room->id)
                    ->where('bookings.booking_status', 'checked_out')
                    ->orderByDesc('bookings.check_out')
                    ->value('bookings.check_out');

                return [
                    'room_id'         => $room->id,
                    'room_number'     => $room->room_number,
                    'room_type'       => $room->room_type,
                    'floor'           => $room->floor,
                    'status'          => $room->status,
                    'preparation_tag' => $room->status === 'cleaning'
                        ? 'Needs Cleaning'
                        : 'Under Maintenance',
                    'last_checkout'   => $lastCheckout ? Carbon::parse($lastCheckout)->toDateString() : null,
                ];
            })
            ->values();

        $isExportAudit = $request->input('audit_event') === 'export_pdf';

        AuditHelper::log(
            actionActivity: $isExportAudit ? 'Occupancy Report Exported (PDF)' : 'Occupancy Report Viewed',
            modulePage: 'Report Management',
            modelType: 'Report',
            modelId: null,
            recordAffected: ($isExportAudit ? 'Occupancy Report PDF' : 'Occupancy Report') . " ({$startDate} to {$endDate})",
            oldValues: null,
            newValues: [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'occupancy_rate' => (float) $occupancyRate,
                'total_rooms' => (int) $totalRooms,
            ],
            action: $isExportAudit ? 'exported' : 'viewed'
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'stats'  => [
                    'total_rooms'       => $totalRooms,
                    'occupied_rooms'    => $occupiedRooms,
                    'available_rooms'   => $availableRooms,
                    'cleaning_rooms'    => $cleaningRooms,
                    'maintenance_rooms' => $maintenanceRooms,
                    // occupancy_rate is calculated against sellable (available) rooms,
                    // not total inventory. Maintenance/cleaning rooms are excluded from
                    // the denominator because they cannot be sold during that period.
                    'occupancy_denominator' => 'available_rooms',
                    'occupancy_rate'    => $occupancyRate,
                    'occupancy_change'  => $occupancyChange,
                    'occupied_room_nights'  => (float) $occupiedRoomNights,
                    'available_room_nights' => (float) $availableRoomNights,
                ],
                'rooms_by_type'      => $roomsByType,
                'daily_occupancy'    => $dailyOccupancy,
                'status_breakdown'   => $statusBreakdown,
                'rooms_for_preparation' => $roomsForPreparation,
            ],
        ]);
    }
}
