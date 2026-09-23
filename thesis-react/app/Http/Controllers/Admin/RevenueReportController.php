<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\FinancialReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RevenueReportController extends Controller
{
    public function __construct(private FinancialReportingService $financialReporting) {}

    /**
     * GET /api/admin/reports/revenue
     * Query params: start_date, end_date
     *
     * Revenue is sourced from the payments table exclusively:
     *   - payment_status = 'completed'
     *   - paid_at IS NOT NULL
     *   - paid_at within the requested date range
     *
     * This covers both flows:
     *   Manual GCash: staff approval sets payment_status = completed and paid_at.
     *   Manual:   receptionist accepts → payment_status = completed, paid_at = now()
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'sort_by' => 'sometimes|in:reference,guest_name,room,type,nights,status,total,amount_collected,amount_refunded,net_amount,payment_method,paid_at,created_at',
            'sort_direction' => 'sometimes|in:asc,desc',
        ]);

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date',   now()->toDateString());
        $sortBy   = $request->input('sort_by', 'created_at');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $startDateTime = Carbon::parse($startDate)->startOfDay();
        $endDateTime   = Carbon::parse($endDate)->endOfDay();

        // ── STAT CARDS ────────────────────────────────────────────────────────

        $summary = $this->financialReporting->summary($startDateTime, $endDateTime);
        $grossRevenue = $summary['gross_revenue'];
        $totalRefunds = $summary['total_refunds'];
        $netRevenue = $summary['net_revenue'];

        $totalBookings = $this->financialReporting
            ->ledgerQuery($startDateTime, $endDateTime)
            ->distinct('booking_id')
            ->count('booking_id');

        $averageBookingValue = $totalBookings > 0
            ? round($netRevenue / $totalBookings, 2)
            : 0;

        // ── PREVIOUS PERIOD (for % change badges) ────────────────────────────
        $diffDays  = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $prevEnd   = Carbon::parse($startDate)->subDay()->toDateString();
        $prevStart = Carbon::parse($prevEnd)->subDays($diffDays - 1)->toDateString();

        $prevStartDateTime = Carbon::parse($prevStart)->startOfDay();
        $prevEndDateTime = Carbon::parse($prevEnd)->endOfDay();
        $previousSummary = $this->financialReporting->summary($prevStartDateTime, $prevEndDateTime);
        $prevNetRevenue = $previousSummary['net_revenue'];

        $prevBookings = $this->financialReporting
            ->ledgerQuery($prevStartDateTime, $prevEndDateTime)
            ->distinct('booking_id')
            ->count('booking_id');

        $revenueChange  = abs($prevNetRevenue) > 0.00001
            ? round((($netRevenue - $prevNetRevenue) / abs($prevNetRevenue)) * 100, 1) : null;
        $bookingsChange = $prevBookings > 0
            ? round((($totalBookings - $prevBookings) / $prevBookings) * 100, 1) : null;

        // ── BOOKING TRANSACTIONS TABLE ────────────────────────────────────────
        $bookings = Booking::with([
                'primaryGuest:id,booking_id,name,email',
                'rooms:id,room_number,room_type',
                'bookingRooms:id,booking_id,room_id,requested_room_type',
                'bookingRooms.room:id,room_number,room_type',
                'payments' => fn($query) => $this->financialReporting
                    ->constrainLedger($query, $startDateTime, $endDateTime),
            ])
            ->whereHas('payments', fn($query) => $this->financialReporting
                ->constrainLedger($query, $startDateTime, $endDateTime))
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($booking) {
                $guest       = $booking->primaryGuest;
                $roomTypes = $booking->bookingRooms
                    ->map(fn ($line) => (string) ($line->room?->room_type ?? $line->requested_room_type))
                    ->filter()
                    ->unique()
                    ->implode(', ');
                $assignedRoomNumbers = $booking->bookingRooms
                    ->map(fn ($line) => $line->room?->room_number)
                    ->filter()
                    ->unique()
                    ->implode(', ');
                $hasPendingAssignmentLines = $booking->bookingRooms->contains(fn ($line) => empty($line->room_id));
                $effectiveRoomAssignmentStatus = $hasPendingAssignmentLines ? 'pending_assignment' : 'assigned';
                $isPendingLabelAllowed = in_array((string) $booking->booking_status, ['pending', 'confirmed'], true);
                $roomNumbers = $assignedRoomNumbers !== ''
                    ? $assignedRoomNumbers
                    : ($isPendingLabelAllowed && $hasPendingAssignmentLines ? 'Pending Assignment' : 'N/A');

                $collections = $booking->payments
                    ->filter(fn (Payment $payment) => $this->financialReporting->isCollection($payment));
                $refunds = $booking->payments
                    ->filter(fn (Payment $payment) => $this->financialReporting->isRefund($payment));
                $amountCollected = round((float) $collections->sum('amount'), 2);
                $amountRefunded = round((float) $refunds->sum('amount'), 2);
                $netAmount = round($amountCollected - $amountRefunded, 2);

                $paymentChannels = $collections->map(function ($p) {
                    return Payment::paymentMethodLabel($p->payment_method, $p->provider);
                })->unique()->values();

                if ($paymentChannels->isEmpty() && $refunds->isNotEmpty()) {
                    $paymentChannels = $refunds->map(function ($payment) {
                        return Payment::paymentMethodLabel($payment->payment_method, $payment->provider);
                    })->unique()->values();
                }

                $latestActivity = $booking->payments->sortByDesc('paid_at')->first()?->paid_at;

                return [
                    'id'               => $booking->id,
                    'reference'        => $booking->reference_number,
                    'guest_name'       => $guest?->name  ?? 'N/A',
                    'guest_email'      => $guest?->email ?? '',
                    'room_numbers'     => $roomNumbers ?: 'N/A',
                    'room_type'        => $roomTypes   ?: 'N/A',
                    'room_assignment_status' => $effectiveRoomAssignmentStatus,
                    'check_in'         => $booking->check_in->toDateString(),
                    'check_out'        => $booking->check_out->toDateString(),
                    'nights'           => $booking->nights,
                    'booking_status'   => $booking->booking_status,
                    'total_amount'     => (float) $booking->total_amount,
                    'amount_collected' => $amountCollected,
                    'amount_refunded'  => $amountRefunded,
                    'net_amount'       => $netAmount,
                    'amount_paid'      => $netAmount,
                    'payment_channels' => $paymentChannels,    // replaces 'payment_methods'
                    'payment_method_sort' => $paymentChannels->implode(', '),
                    'paid_at'          => $latestActivity?->toDateString(),
                    'financial_activity_at' => $latestActivity?->toDateTimeString(),
                    'created_at'       => optional($booking->created_at)->toDateTimeString(),
                ];
            });

        $transactionSortMap = [
            'reference'      => 'reference',
            'guest_name'     => 'guest_name',
            'room'           => 'room_numbers',
            'type'           => 'room_type',
            'nights'         => 'nights',
            'status'         => 'booking_status',
            'total'          => 'total_amount',
            'amount_collected' => 'amount_collected',
            'amount_refunded' => 'amount_refunded',
            'net_amount'     => 'net_amount',
            'payment_method' => 'payment_method_sort',
            'paid_at'        => 'paid_at',
            'created_at'     => 'created_at',
        ];
        $bookings = $this->sortMappedRows(
            $bookings,
            $transactionSortMap[$sortBy] ?? 'created_at',
            $sortDirection
        );

        // ── REVENUE BY PAYMENT CHANNEL ────────────────────────────────────────
        // Normalize all persisted variants into canonical channels:
        //   cash          = paid-at-hotel / manual cash collection
        //   gcash         = manual GCash entries
        //   bank_transfer = manual transfer entries
        $byPaymentMethod = $this->financialReporting
            ->collectionsQuery($startDateTime, $endDateTime)
            ->selectRaw("
                CASE
                    WHEN LOWER(TRIM(payment_method)) = 'gcash' THEN 'gcash'
                    WHEN LOWER(TRIM(payment_method)) IN ('bank_transfer', 'bank transfer', 'bank-transfer', 'banktransfer') THEN 'bank_transfer'
                    ELSE 'cash'
                END as method,
                SUM(amount) as total,
                COUNT(*) as count
            ")
            ->groupBy('method')
            ->get()
            ->map(fn($r) => [
                'method'   => $r->method,
                'label'    => Payment::paymentMethodLabel($r->method, null),
                'total'    => (float) $r->total,
                'count'    => (int) $r->count,
            ]);

        // ── DAILY REVENUE (chart data) ────────────────────────────────────────
        $dailyRevenue = $this->financialReporting
            ->collectionsQuery($startDateTime, $endDateTime)
            ->select(DB::raw('DATE(paid_at) as date'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => (float) $r->total]);

        $dailyRefunds = $this->financialReporting
            ->refundsQuery($startDateTime, $endDateTime)
            ->select(DB::raw('DATE(paid_at) as date'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => (float) $r->total]);

        $isExportAudit = $request->input('audit_event') === 'export_pdf';

        AuditHelper::log(
            actionActivity: $isExportAudit ? 'Revenue Report Exported (PDF)' : 'Revenue Report Viewed',
            modulePage: 'Report Management',
            modelType: 'Report',
            modelId: null,
            recordAffected: ($isExportAudit ? 'Revenue Report PDF' : 'Revenue Report') . " ({$startDate} to {$endDate})",
            oldValues: null,
            newValues: [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'sort_by' => $sortBy,
                'sort_direction' => $sortDirection,
                'total_revenue' => (float) $netRevenue,
                'total_bookings' => (int) $totalBookings,
            ],
            action: $isExportAudit ? 'exported' : 'viewed'
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'stats'  => [
                    'total_revenue'           => (float) $netRevenue,
                    'gross_revenue'           => (float) $grossRevenue,
                    'total_refunds'           => (float) $totalRefunds,
                    'total_bookings'          => $totalBookings,
                    'average_booking_value'   => $averageBookingValue,
                    'revenue_change_percent'  => $revenueChange,
                    'bookings_change_percent' => $bookingsChange,
                ],
                'transactions'      => $bookings,
                'by_payment_method' => $byPaymentMethod,
                'daily_revenue'     => $dailyRevenue,
                'daily_refunds'     => $dailyRefunds,
            ],
        ]);
    }

    private function sortMappedRows($rows, string $field, string $direction)
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $items = $rows->values()->all();

        usort($items, function ($a, $b) use ($field, $direction) {
            $valueA = $a[$field] ?? null;
            $valueB = $b[$field] ?? null;

            if (is_array($valueA)) {
                $valueA = implode(', ', $valueA);
            }
            if (is_array($valueB)) {
                $valueB = implode(', ', $valueB);
            }

            $emptyA = $valueA === null || $valueA === '';
            $emptyB = $valueB === null || $valueB === '';

            if ($emptyA && $emptyB) {
                return 0;
            }
            if ($emptyA) {
                return 1;
            }
            if ($emptyB) {
                return -1;
            }

            if (is_numeric($valueA) && is_numeric($valueB)) {
                $result = (float) $valueA <=> (float) $valueB;
            } else {
                $result = strnatcasecmp((string) $valueA, (string) $valueB);
            }

            return $direction === 'asc' ? $result : -$result;
        });

        return collect($items)->values();
    }
}
