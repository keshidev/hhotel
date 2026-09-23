<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CancellationApprovalRequest;
use App\Models\EarlyCheckInRequest;
use App\Models\ManualGcashSubmission;
use App\Models\Room;
use App\Models\Payment;
use App\Models\RoomTransferRequest;
use App\Models\User;
use App\Services\FinancialReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private FinancialReportingService $financialReporting) {}

    public function index()
    {
        $totalFinancials = $this->financialReporting->summary();
        $monthlyFinancials = $this->financialReporting->summary(
            now()->startOfMonth(),
            now()->endOfMonth()
        );

        $roomCounts = Room::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available")
            ->selectRaw("SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied")
            ->selectRaw("SUM(CASE WHEN status = 'cleaning' THEN 1 ELSE 0 END) as cleaning")
            ->selectRaw("SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance")
            ->first();

        $totalRooms = (int) ($roomCounts->total ?? 0);
        $availableRooms = (int) ($roomCounts->available ?? 0);
        $occupiedRooms = (int) ($roomCounts->occupied ?? 0);
        $cleaningRooms = (int) ($roomCounts->cleaning ?? 0);
        $maintenanceRooms = (int) ($roomCounts->maintenance ?? 0);
        $operationalRooms = max(0, $totalRooms - $maintenanceRooms);
        $occupancyRate = $operationalRooms > 0
            ? round(($occupiedRooms / $operationalRooms) * 100, 1)
            : 0.0;

        $attention = [
            'gcash_reviews' => ManualGcashSubmission::whereIn('status', [
                ManualGcashSubmission::STATUS_PENDING,
                ManualGcashSubmission::STATUS_ESCALATED,
            ])->count(),
            'cancellations' => CancellationApprovalRequest::where(function ($query) {
                $query->whereIn('status', [
                    CancellationApprovalRequest::STATUS_PENDING_APPROVAL,
                    CancellationApprovalRequest::STATUS_REFUND_PENDING,
                ])->orWhere(function ($finalizationQuery) {
                    $finalizationQuery
                        ->whereIn('status', [
                            CancellationApprovalRequest::STATUS_APPROVED,
                            CancellationApprovalRequest::STATUS_REFUNDED,
                        ])
                        ->whereNull('finalized_at');
                });
            })->count(),
            'transfers' => RoomTransferRequest::where(
                'status',
                RoomTransferRequest::STATUS_PENDING_APPROVAL
            )->count(),
            'early_check_ins' => EarlyCheckInRequest::where(
                'status',
                EarlyCheckInRequest::STATUS_PENDING
            )->count(),
        ];
        $attention['total'] = array_sum($attention);

        $stats = [
            // Room Statistics
            'total_rooms' => $totalRooms,
            'operational_rooms' => $operationalRooms,
            'available_rooms' => $availableRooms,
            'occupied_rooms' => $occupiedRooms,
            'cleaning_rooms' => $cleaningRooms,
            'maintenance_rooms' => $maintenanceRooms,
            'current_occupancy_rate' => $occupancyRate,
            
            // Booking Statistics
            'total_bookings' => Booking::visibleToStaff()->count(),
            'pending_bookings' => Booking::visibleToStaff()->where('booking_status', 'pending')->count(),
            'confirmed_bookings' => Booking::visibleToStaff()->where('booking_status', 'confirmed')->count(),
            'checked_in_bookings' => Booking::visibleToStaff()->where('booking_status', 'checked_in')->count(),
            
            // Today's Statistics
            'today_check_ins' => Booking::visibleToStaff()->whereDate('check_in', today())
                ->whereIn('booking_status', ['pending', 'confirmed'])
                ->count(),
            'today_check_outs' => Booking::visibleToStaff()->whereDate('check_out', today())
                ->where('booking_status', 'checked_in')
                ->count(),
            
            // Revenue Statistics
            'total_gross_revenue' => $totalFinancials['gross_revenue'],
            'total_refunds' => $totalFinancials['total_refunds'],
            'total_revenue' => $totalFinancials['net_revenue'],
            'monthly_gross_revenue' => $monthlyFinancials['gross_revenue'],
            'monthly_refunds' => $monthlyFinancials['total_refunds'],
            'monthly_revenue' => $monthlyFinancials['net_revenue'],
            'pending_payments' => Payment::where('payment_status', 'pending')
                ->where('payment_type', '!=', Payment::TYPE_REFUND)
                ->sum('amount'),
            
            // User Statistics
            'total_staff' => User::count(),
            'active_staff' => User::where('status', 'active')->count(),

            // Work requiring an administrator decision or completion
            'pending_actions' => $attention['total'],
        ];

        // Upcoming Check-ins
        $upcomingCheckIns = Booking::visibleToStaff()->with(['primaryGuest', 'rooms:id,room_number'])
            ->whereDate('check_in', '>=', today())
            ->whereIn('booking_status', ['pending', 'confirmed'])
            ->orderBy('check_in')
            ->limit(5)
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'guest_name' => $booking->primaryGuest?->name ?? 'Guest name unavailable',
                'rooms' => $booking->rooms->pluck('room_number')->implode(', '),
                'check_in' => $booking->check_in?->format('Y-m-d'),
                'booking_status' => $booking->booking_status,
            ]);

        return response()->json([
            'stats' => $stats,
            'attention' => $attention,
            'upcoming_checkins' => $upcomingCheckIns,
        ]);
    }

    public function revenueChart(Request $request)
    {
        $validated = $request->validate([
            'period' => 'sometimes|in:day,week,month',
        ]);
        $period = $validated['period'] ?? 'month';

        $baseQuery = $this->financialReporting->ledgerQuery();
        $netLedgerSql = $this->financialReporting->netLedgerSql();

        switch ($period) {
            case 'day':
                $data = (clone $baseQuery)
                    ->whereDate('paid_at', '>=', now()->subDays(7))
                    ->select(
                        DB::raw('DATE(paid_at) as date'),
                        DB::raw("SUM({$netLedgerSql}) as total")
                    )
                    ->groupBy(DB::raw('DATE(paid_at)'))
                    ->orderBy(DB::raw('DATE(paid_at)'))
                    ->get();
                break;
            
            case 'week':
                $data = (clone $baseQuery)
                    ->whereDate('paid_at', '>=', now()->subWeeks(12))
                    ->select(
                        DB::raw('YEARWEEK(paid_at) as week'),
                        DB::raw("SUM({$netLedgerSql}) as total")
                    )
                    ->groupBy(DB::raw('YEARWEEK(paid_at)'))
                    ->orderBy(DB::raw('YEARWEEK(paid_at)'))
                    ->get();
                break;
            
            case 'month':
                $data = (clone $baseQuery)
                    ->whereDate('paid_at', '>=', now()->subMonths(12))
                    ->select(
                        DB::raw('DATE_FORMAT(paid_at, "%Y-%m") as month'),
                        DB::raw("SUM({$netLedgerSql}) as total")
                    )
                    ->groupBy(DB::raw('DATE_FORMAT(paid_at, "%Y-%m")'))
                    ->orderBy(DB::raw('DATE_FORMAT(paid_at, "%Y-%m")'))
                    ->get();
                break;
            
            default:
                $data = [];
        }

        return response()->json($data);
    }
}
