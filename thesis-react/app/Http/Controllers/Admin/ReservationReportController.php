<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class ReservationReportController extends Controller
{
    /**
     * GET /api/admin/reports/reservations
     * Query params: start_date, end_date
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'status'     => 'sometimes|in:all,pending,confirmed,checked_in,checked_out,cancelled,no_show,expired',
            'room_type'  => 'sometimes|string',
            'search'     => 'sometimes|string',
            'sort_by'    => 'sometimes|in:reference,guest,room,type,status,check_in,check_out,nights,amount,created_at',
            'sort_direction' => 'sometimes|in:asc,desc',
        ]);

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date',   now()->toDateString());

        $startDateTime = $startDate . ' 00:00:00';
        $endDateTime   = $endDate   . ' 23:59:59';
        $endDatePlus   = Carbon::parse($endDate)->addDay()->toDateString();

        $status   = $request->input('status', 'all');
        $roomType = $request->input('room_type');
        $search   = $request->input('search');
        $sortBy   = $request->input('sort_by', 'created_at');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        // ── STAT COUNTS ───────────────────────────────────────────────────
        $base = Booking::visibleToStaff()
            ->where('check_in', '<', $endDatePlus)
            ->where('check_out', '>', $startDate);

        $applyFilters = function ($query) use ($status, $roomType, $search) {
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

            if ($roomType) {
                $query->where(function ($roomFilterQ) use ($roomType) {
                    $roomFilterQ->whereHas('rooms', fn ($q) => $q->where('room_type', $roomType))
                        ->orWhereHas('bookingRooms', fn ($q) => $q->where('requested_room_type', $roomType));
                });
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                      ->orWhereHas('primaryGuest', function ($guestQ) use ($search) {
                          $guestQ->where('name', 'like', "%{$search}%")
                                 ->orWhere('email', 'like', "%{$search}%")
                                 ->orWhere('phone', 'like', "%{$search}%");
                      })
                      ->orWhereHas('rooms', function ($roomQ) use ($search) {
                          $roomQ->where('room_number', 'like', "%{$search}%")
                                ->orWhere('room_type', 'like', "%{$search}%");
                      })
                      ->orWhereHas('bookingRooms', function ($lineQ) use ($search) {
                          $lineQ->where('requested_room_type', 'like', "%{$search}%");
                      });
                });
            }
        };

        $applyNonStatusFilters = function ($query) use ($roomType, $search) {
            if ($roomType) {
                $query->where(function ($roomFilterQ) use ($roomType) {
                    $roomFilterQ->whereHas('rooms', fn ($q) => $q->where('room_type', $roomType))
                        ->orWhereHas('bookingRooms', fn ($q) => $q->where('requested_room_type', $roomType));
                });
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                      ->orWhereHas('primaryGuest', function ($guestQ) use ($search) {
                          $guestQ->where('name', 'like', "%{$search}%")
                                 ->orWhere('email', 'like', "%{$search}%")
                                 ->orWhere('phone', 'like', "%{$search}%");
                      })
                      ->orWhereHas('rooms', function ($roomQ) use ($search) {
                          $roomQ->where('room_number', 'like', "%{$search}%")
                                ->orWhere('room_type', 'like', "%{$search}%");
                      })
                      ->orWhereHas('bookingRooms', function ($lineQ) use ($search) {
                          $lineQ->where('requested_room_type', 'like', "%{$search}%");
                      });
                });
            }
        };

        $filteredBase = clone $base;
        $applyFilters($filteredBase);

        $totalReservations     = (clone $filteredBase)->count();
        $confirmedReservations = (clone $filteredBase)->where('booking_status', 'confirmed')->count();
        $pendingReservations   = (clone $filteredBase)->where('booking_status', 'pending')->count();
        $checkedInReservations = (clone $filteredBase)->where('booking_status', 'checked_in')->count();
        $checkedOutReservations= (clone $filteredBase)->where('booking_status', 'checked_out')->count();
        $noShowReservations = (clone $filteredBase)->where('booking_status', 'no_show')->count();

        $cancelledReservations = (clone $filteredBase)->where('booking_status', 'cancelled')
            ->where(function ($q) {
                $q->whereNull('cancelled_reason')
                  ->orWhere('cancelled_reason', '!=', 'expired_unpaid');
            })->count();

        $expiredReservations = (clone $filteredBase)->where('booking_status', 'cancelled')
            ->where('cancelled_reason', 'expired_unpaid')
            ->count();

        // ── PREVIOUS PERIOD (for % change) ───────────────────────────────
        $diffDays  = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $prevEnd   = Carbon::parse($startDate)->subDay()->toDateString();
        $prevStart = Carbon::parse($prevEnd)->subDays($diffDays - 1)->toDateString();

        $prevEndPlus = Carbon::parse($prevEnd)->addDay()->toDateString();
        $prevTotalQuery = Booking::visibleToStaff()
            ->where('check_in', '<', $prevEndPlus)
            ->where('check_out', '>', $prevStart);
        $applyNonStatusFilters($prevTotalQuery);
        $prevTotal = $prevTotalQuery->count();

        $totalChange = $prevTotal > 0
            ? round((($totalReservations - $prevTotal) / $prevTotal) * 100, 1)
            : null;

        // ── RESERVATION RECORDS TABLE ─────────────────────────────────────
        $reservationsQuery = Booking::visibleToStaff()->with([
                'primaryGuest:id,booking_id,name,email,phone',
                'rooms:id,room_number,room_type',
                'bookingRooms:id,booking_id,room_id,requested_room_type',
                'bookingRooms.room:id,room_number,room_type',
            ])
            ->where('check_in', '<', $endDatePlus)
            ->where('check_out', '>', $startDate);
        $applyFilters($reservationsQuery);

        $reservations = $reservationsQuery
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($booking) {
                $guest       = $booking->primaryGuest;
                $lineRoomTypes = $booking->bookingRooms
                    ->map(fn ($line) => (string) ($line->room?->room_type ?? $line->requested_room_type))
                    ->filter()
                    ->unique()
                    ->values();
                $roomTypes = $lineRoomTypes->implode(', ');
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
                $status = $booking->booking_status;
                if ($status === 'cancelled' && $booking->cancelled_reason === 'expired_unpaid') {
                    $status = 'expired';
                }

                return [
                    'id'             => $booking->id,
                    'reference'      => $booking->reference_number,
                    'guest_name'     => $guest?->name  ?? 'N/A',
                    'guest_email'    => $guest?->email ?? '',
                    'guest_phone'    => $guest?->phone ?? '',
                    'room_numbers'   => $roomNumbers ?: 'N/A',
                    'room_type'      => $roomTypes   ?: 'N/A',
                    'room_assignment_status' => $effectiveRoomAssignmentStatus,
                    'status'         => $status,
                    'check_in'       => $booking->check_in instanceof \Carbon\Carbon
                        ? $booking->check_in->toDateString()
                        : $booking->check_in,
                    'check_out'      => $booking->check_out instanceof \Carbon\Carbon
                        ? $booking->check_out->toDateString()
                        : $booking->check_out,
                    'nights'         => $booking->nights,
                    'total_amount'   => (float) $booking->total_amount,
                    'created_at'     => $booking->created_at->toDateString(),
                ];
            });

        $reservationSortMap = [
            'reference'  => 'reference',
            'guest'      => 'guest_name',
            'room'       => 'room_numbers',
            'type'       => 'room_type',
            'status'     => 'status',
            'check_in'   => 'check_in',
            'check_out'  => 'check_out',
            'nights'     => 'nights',
            'amount'     => 'total_amount',
            'created_at' => 'created_at',
        ];
        $reservations = $this->sortMappedRows(
            $reservations,
            $reservationSortMap[$sortBy] ?? 'created_at',
            $sortDirection
        );

        $reservationTimeline = $reservations
            ->filter(fn ($reservation) => $reservation['check_in'] >= $startDate && $reservation['check_in'] <= $endDate)
            ->groupBy('check_in')
            ->map(function ($rows, $date) {
                $counts = $rows->countBy('status');

                return [
                    'date' => $date,
                    'confirmed' => (int) ($counts['confirmed'] ?? 0),
                    'pending' => (int) ($counts['pending'] ?? 0),
                    'checked_in' => (int) ($counts['checked_in'] ?? 0),
                    'checked_out' => (int) ($counts['checked_out'] ?? 0),
                    'no_show' => (int) ($counts['no_show'] ?? 0),
                    'cancelled' => (int) ($counts['cancelled'] ?? 0),
                    'expired' => (int) ($counts['expired'] ?? 0),
                ];
            })
            ->sortBy('date')
            ->values();

        // ── STATUS BREAKDOWN ──────────────────────────────────────────────
        $statusBreakdown = [
            ['status' => 'Confirmed',   'count' => $confirmedReservations,  'color' => '#10b981'],
            ['status' => 'Pending',     'count' => $pendingReservations,    'color' => '#f59e0b'],
            ['status' => 'Checked In',  'count' => $checkedInReservations,  'color' => '#3b82f6'],
            ['status' => 'Checked Out', 'count' => $checkedOutReservations, 'color' => '#8b5cf6'],
            ['status' => 'No-Show',     'count' => $noShowReservations,     'color' => '#374151'],
            ['status' => 'Cancelled',   'count' => $cancelledReservations,  'color' => '#ef4444'],
            ['status' => 'Expired',     'count' => $expiredReservations,    'color' => '#6b7280'],
        ];

        $isExportAudit = $request->input('audit_event') === 'export_pdf';

        AuditHelper::log(
            actionActivity: $isExportAudit ? 'Reservation Report Exported (PDF)' : 'Reservation Report Viewed',
            modulePage: 'Report Management',
            modelType: 'Report',
            modelId: null,
            recordAffected: ($isExportAudit ? 'Reservation Report PDF' : 'Reservation Report') . " ({$startDate} to {$endDate})",
            oldValues: null,
            newValues: [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status_filter' => $status,
                'room_type_filter' => $roomType,
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_direction' => $sortDirection,
                'total_reservations' => (int) $totalReservations,
            ],
            action: $isExportAudit ? 'exported' : 'viewed'
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'stats'  => [
                    'total_reservations'      => $totalReservations,
                    'confirmed_reservations'  => $confirmedReservations,
                    'pending_reservations'    => $pendingReservations,
                    'cancelled_reservations'  => $cancelledReservations,
                    'no_show_reservations'    => $noShowReservations,
                    'expired_reservations'    => $expiredReservations,
                    'checked_in_reservations' => $checkedInReservations,
                    'checked_out_reservations'=> $checkedOutReservations,
                    'total_change'            => $totalChange,
                ],
                'reservations'     => $reservations,
                'reservation_timeline' => $reservationTimeline,
                'status_breakdown' => $statusBreakdown,
            ],
        ]);
    }

    /**
     * GET /api/admin/reports/reservations/modified
     * Query params: start_date, end_date
     */
    public function modified(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'include_all' => 'sometimes|boolean',
            'sort_by' => 'sometimes|in:modified_at,reference,modified_by,action,changed_fields',
            'sort_direction' => 'sometimes|in:asc,desc',
        ]);

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date', now()->toDateString());
        $sortBy   = $request->input('sort_by', 'modified_at');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $activityTable = (new ActivityLog())->getTable();
        if (!Schema::hasTable($activityTable)) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                    'stats'  => [
                        'total_modifications'          => 0,
                        'unique_reservations_modified' => 0,
                    ],
                    'top_modifiers' => [],
                    'top_changed_fields' => [],
                    'changes'       => [],
                ],
            ]);
        }

        $modelTypes = [
            'Booking',
            'BookingRoom',
            'BookingGuest',
            Booking::class,
            \App\Models\BookingRoom::class,
            \App\Models\BookingGuest::class,
        ];

        $logs = ActivityLog::query()
            ->whereIn('model_type', $modelTypes)
            ->where('action', 'updated')
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->orderByDesc('created_at')
            ->get();

        $bookingIds = $logs->where('model_type', 'Booking')
            ->pluck('model_id')
            ->filter()
            ->unique()
            ->values();

        $bookingRoomIds = $logs->where('model_type', 'BookingRoom')
            ->pluck('model_id')
            ->filter()
            ->unique()
            ->values();

        $bookingGuestIds = $logs->where('model_type', 'BookingGuest')
            ->pluck('model_id')
            ->filter()
            ->unique()
            ->values();

        $bookingRefs = Booking::whereIn('id', $bookingIds)
            ->pluck('reference_number', 'id')
            ->all();

        $bookingRoomMap = \App\Models\BookingRoom::whereIn('id', $bookingRoomIds)
            ->pluck('booking_id', 'id')
            ->all();

        $bookingGuestMap = \App\Models\BookingGuest::whereIn('id', $bookingGuestIds)
            ->pluck('booking_id', 'id')
            ->all();

        $allowedFields = [
            'room_id',
            'room_number',
            'room_type',
            'check_in',
            'check_out',
            'number_of_guests',
            'total_amount',
            'price_per_night',
            'subtotal',
            'special_requests',
            'booking_status',
            'reservation_status',
            'payment_status',
            'cancelled_reason',
            'expires_at',
            'guest_name',
            'guest_email',
            'guest_phone',
        ];

        $includeAll = (bool) $request->boolean('include_all', false);

        $changes = $logs->map(function ($log) use ($bookingRefs, $allowedFields, $includeAll, $bookingRoomMap, $bookingGuestMap) {
            $old = is_array($log->old_values) ? $log->old_values : [];
            $new = is_array($log->new_values) ? $log->new_values : [];
            $changedFields = collect(array_unique(array_merge(array_keys($old), array_keys($new))))
                ->values()
                ->all();

            $bookingId = $log->model_id;
            if ($log->model_type === 'BookingRoom') {
                $bookingId = $bookingRoomMap[$log->model_id] ?? null;
            } elseif ($log->model_type === 'BookingGuest') {
                $bookingId = $bookingGuestMap[$log->model_id] ?? null;
            }

            $meaningfulFields = array_values(array_intersect($changedFields, $allowedFields));
            if (!$includeAll && empty($meaningfulFields)) {
                return null;
            }

            $visibleFields = $includeAll ? $changedFields : $meaningfulFields;
            $fieldChanges = collect($visibleFields)
                ->map(fn ($field) => [
                    'field' => $field,
                    'old'   => $old[$field] ?? null,
                    'new'   => $new[$field] ?? null,
                ])
                ->values()
                ->all();

            return [
                'id'               => $log->id,
                'reservation_id'   => $bookingId,
                'reference_number' => $bookingId ? ($bookingRefs[$bookingId] ?? null) : null,
                'source_model'     => $log->model_type,
                'source_id'        => $log->model_id,
                'action_activity'  => $log->action_activity,
                'module_page'      => $log->module_page,
                'modified_by'      => $log->user_staff_name ?? 'System',
                'changed_fields'   => $visibleFields,
                'field_changes'    => $fieldChanges,
                'old_values'       => $old,
                'new_values'       => $new,
                'modified_at'      => optional($log->created_at)->toDateTimeString(),
            ];
        })->filter()->values();

        $modifiedSortMap = [
            'modified_at'   => 'modified_at',
            'reference'     => 'reference_number',
            'modified_by'   => 'modified_by',
            'action'        => 'action_activity',
            'changed_fields'=> 'changed_fields',
        ];
        $changes = $this->sortMappedRows(
            $changes,
            $modifiedSortMap[$sortBy] ?? 'modified_at',
            $sortDirection
        );

        $topModifiers = $changes
            ->groupBy(fn ($row) => $row['modified_by'] ?? 'System')
            ->map(fn ($rows, $name) => ['name' => $name, 'count' => $rows->count()])
            ->sortByDesc('count')
            ->values()
            ->take(5)
            ->values();

        $topChangedFields = $changes
            ->flatMap(fn ($row) => $row['changed_fields'] ?? [])
            ->countBy()
            ->map(fn ($count, $field) => ['field' => $field, 'count' => $count])
            ->sortByDesc('count')
            ->take(8)
            ->values();

        $isExportAudit = $request->input('audit_event') === 'export_pdf';

        AuditHelper::log(
            actionActivity: $isExportAudit ? 'Modified Reservation Report Exported (PDF)' : 'Modified Reservation Report Viewed',
            modulePage: 'Report Management',
            modelType: 'Report',
            modelId: null,
            recordAffected: ($isExportAudit ? 'Modified Reservation Report PDF' : 'Modified Reservation Report') . " ({$startDate} to {$endDate})",
            oldValues: null,
            newValues: [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'include_all' => $includeAll,
                'sort_by' => $sortBy,
                'sort_direction' => $sortDirection,
                'total_modifications' => (int) $changes->count(),
                'unique_reservations_modified' => (int) $changes->pluck('reservation_id')->filter()->unique()->count(),
            ],
            action: $isExportAudit ? 'exported' : 'viewed'
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'stats'  => [
                    'total_modifications'          => $changes->count(),
                    'unique_reservations_modified' => $changes->pluck('reservation_id')->filter()->unique()->count(),
                ],
                'top_modifiers' => $topModifiers,
                'top_changed_fields' => $topChangedFields,
                'changes'       => $changes,
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
