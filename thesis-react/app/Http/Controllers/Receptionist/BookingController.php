<?php

namespace App\Http\Controllers\Receptionist;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\User;
use App\Helpers\NotificationHelper;
use App\Helpers\AuditHelper;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Mail\BookingConfirmation;
use App\Mail\BookingRejected;
use App\Services\RoomStateService;
use App\Services\CancellationApprovalService;
use App\Services\FrontDeskGcashPaymentService;
use App\Services\FinancialReportingService;
use App\Services\RoomAssignmentService;
use App\Services\CheckInService;
use App\Services\CheckoutStayService;
use App\Services\NoShowService;
use App\Http\Controllers\FeedbackController;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Hotel policy constants (single source of truth for both controllers)
    // ─────────────────────────────────────────────────────────────────────────

    /** Official check-in hour (24h) — 15:00 = 3:00 PM */
    public const CHECK_IN_HOUR  = 15;

    /** Official check-out hour (24h) — 12:00 = 12:00 PM */
    public const CHECK_OUT_HOUR = 12;

    /** Valid room statuses that allow a guest to check in */
    public const CHECKIN_ALLOWED_STATUSES = ['available', 'clean'];

    /** Room statuses that block check-in */
    public const CHECKIN_BLOCKED_STATUSES = ['occupied', 'cleaning', 'maintenance'];

    public function __construct(
        private RoomStateService $roomStateService,
        private CancellationApprovalService $cancellationApprovalService,
        private RoomAssignmentService $roomAssignmentService,
        private FrontDeskGcashPaymentService $frontDeskGcashPayments,
        private CheckInService $checkInService,
        private CheckoutStayService $checkoutStayService,
        private NoShowService $noShowService,
        private FinancialReportingService $financialReporting,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $query = Booking::visibleToStaff()
                ->with(['bookingRooms.room', 'primaryGuest', 'creator', 'payments']);

            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('booking_status', $request->status);
            }

            $dateField = in_array($request->get('date_field'), ['check_in', 'check_out'])
                ? $request->get('date_field') : 'check_in';

            if ($request->filled('start_date')) {
                $query->whereDate($dateField, '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate($dateField, '<=', $request->end_date);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                      ->orWhereHas('primaryGuest', function ($gq) use ($search) {
                          $gq->where('name',  'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      });
                });
            }

            $sortBy    = in_array($request->get('sort_by'), ['created_at', 'check_in', 'check_out', 'total_amount'])
                ? $request->get('sort_by') : 'created_at';
            $sortOrder = $request->get('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sortBy, $sortOrder);

            $bookings = $query->paginate($request->get('per_page', 15));

            $bookings->getCollection()->transform(function (Booking $booking) {
                return $this->appendRoomSummary($booking);
            });

            return response()->json([
                'success' => true,
                'data' => $bookings,
                'meta' => [
                    'no_show_cutoff_time' => $this->noShowService->cutoffTime(),
                    'no_show_cutoff_label' => $this->noShowService->cutoffLabel(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch receptionist bookings', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHOW
    // ─────────────────────────────────────────────────────────────────────────

    public function show($id)
    {
        try {
            $booking = Booking::visibleToStaff()
                ->with(['bookingRooms.room', 'primaryGuest', 'guests', 'creator', 'payments'])
                ->where(function ($query) use ($id) {
                    $query->where('reference_number', $id)->orWhere('id', $id);
                })
                ->firstOrFail();

            AuditHelper::log(
                actionActivity: 'Booking Viewed',
                modulePage: 'Booking Module',
                modelType: 'Booking',
                modelId: (int) $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number . ' - ' . ($booking->primaryGuest->name ?? 'Guest'),
                oldValues: null,
                newValues: [
                    'viewed_by' => auth()->user()->name ?? 'Receptionist',
                    'booking_status' => $booking->booking_status,
                ],
                action: 'viewed'
            );

            $booking = $this->appendRoomSummary($booking);

            return response()->json(['success' => true, 'data' => $booking]);

        } catch (\Exception $e) {
            Log::warning('Booking not found in receptionist show', ['booking_key' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false, 'message' => 'Booking not found.',
            ], 404);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIRM
    // ─────────────────────────────────────────────────────────────────────────

    public function confirm(Request $request, $id)
    {
        try {
            $booking          = null;
            $completedPayment = null;

            DB::transaction(function () use ($id, $request, &$booking, &$completedPayment) {
                $booking = Booking::with(['primaryGuest', 'bookingRooms.room', 'payments'])
                    ->where('reference_number', $id)
                    ->orWhere('id', $id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($booking->booking_status !== 'pending') {
                    throw new \RuntimeException('STATUS_INVALID:' . $booking->booking_status);
                }

                $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
                    booking: $booking,
                    actionCode: 'receptionist_confirm'
                );

                if ($booking->isExpiredPending()) {
                    $this->cancellationApprovalService->cancelLockedImmediately(
                        booking: $booking,
                        payload: [
                            'reason' => 'Booking expired before confirmation.',
                            'cancelled_reason_code' => 'expired_unpaid',
                            'refund_status' => 'none',
                            'refund_amount' => 0,
                            'request_note' => 'Automatic cancellation while receptionist tried to confirm expired booking.',
                        ],
                        actorUser: auth()->user(),
                        actorLabel: null
                    );
                    throw new \RuntimeException('BOOKING_EXPIRED');
                }

                $completedPayment = $booking->payments()
                    ->where('payment_status', 'completed')
                    ->lockForUpdate()
                    ->first();

                if (!$completedPayment) {
                    $message = 'Payment must be verified and completed before confirming this booking.';
                    throw new \RuntimeException('PAYMENT_INCOMPLETE:' . $message);
                }

                // RULE 3 — Double-booking prevention
                $roomIds = $booking->bookingRooms()->pluck('room_id')->filter()->map(fn ($id) => (int) $id)->all();
                if (!empty($roomIds)) {
                    $hasConflict = DB::table('booking_rooms')
                        ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
                        ->whereIn('booking_rooms.room_id', $roomIds)
                        ->where('bookings.id', '!=', $booking->id)
                        ->where(function ($activeQ) {
                            $activeQ->whereNull('booking_rooms.room_status')
                                ->orWhere('booking_rooms.room_status', 'active');
                        })
                        ->whereIn('bookings.booking_status', ['confirmed', 'checked_in'])
                        ->where('bookings.check_in', '<', $booking->check_out)
                        ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$booking->check_in])
                        ->exists();

                    if ($hasConflict) {
                        throw new \RuntimeException('ROOM_CONFLICT');
                    }
                }

                $booking->update([
                    'booking_status'     => 'confirmed',
                    'reservation_status' => 'confirmed',
                    'cancelled_reason'   => null,
                ]);

                $this->roomAssignmentService->assignPendingRooms(
                    booking: $booking,
                    actorLabel: auth()->user()?->name ?? 'Receptionist'
                );

                if ($request->filled('notes')) {
                    $booking->update([
                        'special_requests' => ($booking->special_requests ?? '')
                            . "\n\n[Receptionist Note]: " . $request->notes,
                    ]);
                }
            });

            AuditHelper::log(
                actionActivity: 'Booking Confirmed',
                modulePage:     'Booking Module',
                modelType:      'Booking',
                modelId:        $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number . ' — ' . ($booking->primaryGuest?->name ?? ''),
                oldValues:      ['booking_status' => 'pending'],
                newValues:      [
                    'booking_status'   => 'confirmed',
                    'confirmed_by'     => auth()->user()->name,
                    'payment_provider' => $completedPayment->provider ?? 'manual',
                ],
                action: 'updated'
            );

            $guestName   = $booking->primaryGuest->name  ?? 'Guest';
            $guestEmail  = $booking->primaryGuest->email ?? null;
            $refNumber   = $booking->reference_number;
            $roomNumbers = $booking->bookingRooms->pluck('room.room_number')->join(', ');
            $actorName   = auth()->user()?->name ?? 'Receptionist';

            if ($guestEmail) {
                try {
                    $booking->load(['bookingRooms.room', 'primaryGuest', 'payments']);
                    Mail::to($guestEmail)->queue(new BookingConfirmation($booking));
                } catch (\Exception $e) {
                    $fallbackMail = new BookingConfirmation($booking);

                    try {
                        Mail::to($guestEmail)->sendNow($fallbackMail);
                        Log::info('BookingConfirmation email sent immediately (fallback)', [
                            'booking_id' => $booking->id,
                            'email'      => $guestEmail,
                        ]);
                    } catch (\Exception $e2) {
                        $fallbackMail->failed($e2);
                        Log::warning('BookingConfirmation email failed (both queue and send): ' . $e2->getMessage(), [
                            'booking_id' => $booking->id,
                            'email'      => $guestEmail,
                            'queue_error' => $e->getMessage(),
                            'send_error'   => $e2->getMessage(),
                        ]);
                    }
                }
            }

            $this->notifyStaff(
                type:    NotificationType::BOOKING_CONFIRMED,
                title:   'Booking Confirmed',
                message: "Booking {$refNumber} for {$guestName} (Room {$roomNumbers}) confirmed by {$actorName}.",
                data:    [
                    'booking_id'       => $booking->id,
                    'reference_number' => $refNumber,
                    'guest_name'       => $guestName,
                    'confirmed_by'     => $actorName,
                ]
            );

            $booking->load(['bookingRooms.room', 'primaryGuest', 'creator', 'payments']);

            return response()->json([
                'success' => true, 'message' => 'Booking confirmed successfully.', 'data' => $booking,
            ]);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'STATUS_INVALID:')) {
                $status = substr($e->getMessage(), strlen('STATUS_INVALID:'));
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending bookings can be confirmed. Current status: ' . $status . '.',
                ], 400);
            }
            if ($e->getMessage() === 'BOOKING_EXPIRED') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking expired before it could be confirmed. Please create a new reservation.',
                ], 410);
            }
            if ($e->getMessage() === 'ROOM_CONFLICT') {
                return response()->json([
                    'success' => false,
                    'message' => 'This room is already reserved for the selected dates.',
                ], 409);
            }
            if (str_starts_with($e->getMessage(), 'PAYMENT_INCOMPLETE:')) {
                return response()->json([
                    'success' => false,
                    'message' => substr($e->getMessage(), strlen('PAYMENT_INCOMPLETE:')),
                ], 400);
            }
            if (str_starts_with($e->getMessage(), 'BOOKING_LIFECYCLE_LOCKED:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking cannot be confirmed while a cancellation request is in progress.',
                ], 409);
            }
            throw $e;

        } catch (\Exception $e) {
            Log::error('Booking confirmation failed', ['booking_key' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm booking.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REJECT
    // ─────────────────────────────────────────────────────────────────────────

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        try {
            $booking = null;

            DB::transaction(function () use ($id, $request, &$booking) {
                $booking = Booking::with(['primaryGuest', 'bookingRooms.room', 'payments'])
                    ->where('reference_number', $id)
                    ->orWhere('id', $id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($booking->booking_status !== 'pending') {
                    throw new \RuntimeException('STATUS_INVALID:' . $booking->booking_status);
                }

                $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
                    booking: $booking,
                    actionCode: 'receptionist_reject'
                );

                $paidAmount = (float) $booking->payments()
                    ->where('payment_status', 'completed')
                    ->where('payment_type', '!=', 'refund')
                    ->sum('amount');

                if ($paidAmount > 0.00001) {
                    throw new \RuntimeException('CANCELLATION_APPROVAL_REQUIRED');
                }

                $this->cancellationApprovalService->cancelLockedImmediately(
                    booking: $booking,
                    payload: [
                        'reason' => (string) $request->reason,
                        'cancelled_reason_code' => 'manual_reject',
                        'refund_status' => 'none',
                        'refund_amount' => 0,
                        'request_note' => 'Automatic cancellation from receptionist booking rejection.',
                    ],
                    actorUser: auth()->user(),
                    actorLabel: null
                );

                $booking->update([
                    'special_requests' => ($booking->special_requests ?? '')
                        . "\n\n[Cancelled by Receptionist]: " . $request->reason,
                ]);
            });

            AuditHelper::log(
                actionActivity: 'Booking Rejected',
                modulePage:     'Booking Module',
                modelType:      'Booking',
                modelId:        $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number . ' — ' . ($booking->primaryGuest?->name ?? ''),
                oldValues:      ['booking_status' => 'pending'],
                newValues:      ['booking_status' => 'cancelled', 'reason' => $request->reason],
                action:         'updated'
            );

            $guestName   = $booking->primaryGuest->name  ?? 'Guest';
            $guestEmail  = $booking->primaryGuest->email ?? null;
            $refNumber   = $booking->reference_number;
            $roomNumbers = $booking->bookingRooms->pluck('room.room_number')->join(', ');
            $actorName   = auth()->user()?->name ?? 'Receptionist';

            if ($guestEmail) {
                try {
                    Mail::to($guestEmail)->queue(new BookingRejected($booking, $request->reason));
                } catch (\Exception $e) {
                    Log::warning('BookingRejected email failed: ' . $e->getMessage());
                }
            }

            $this->notifyStaff(
                type:    'booking_cancelled',
                title:   'Booking Rejected',
                message: "Booking {$refNumber} for {$guestName} (Room {$roomNumbers}) rejected by {$actorName}. Reason: {$request->reason}",
                data:    [
                    'booking_id'       => $booking->id,
                    'reference_number' => $refNumber,
                    'guest_name'       => $guestName,
                    'rejected_by'      => $actorName,
                    'reason'           => $request->reason,
                ]
            );

            $booking->load(['bookingRooms.room', 'primaryGuest', 'creator', 'payments']);

            return response()->json([
                'success' => true, 'message' => 'Booking rejected successfully.', 'data' => $booking,
            ]);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'STATUS_INVALID:')) {
                $status = substr($e->getMessage(), strlen('STATUS_INVALID:'));
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending bookings can be rejected. Current status: ' . $status . '.',
                ], 400);
            }
            if ($e->getMessage() === 'CANCELLATION_APPROVAL_REQUIRED') {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking has paid amounts. Submit a cancellation request for admin approval instead of direct rejection.',
                ], 403);
            }
            if (str_starts_with($e->getMessage(), 'BOOKING_LIFECYCLE_LOCKED:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking cannot be rejected while a cancellation request is in progress.',
                ], 409);
            }
            throw $e;

        } catch (\Exception $e) {
            Log::error('Booking rejection failed', ['booking_key' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject booking.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHECK-IN
    // RULE 1    — Enforce 3:00 PM check-in time; allow override if room is ready.
    // RULE 4    — Room must not be occupied / cleaning / maintenance.
    // RULE 7    — Early check-in: allowed only with receptionist override flag.
    // NEW FIX   — Block check-in if today is before the booking's check-in date.
    // ─────────────────────────────────────────────────────────────────────────

    public function checkIn(Request $request, $id)
    {
        try {
            $result = $this->checkInService->checkIn(
                bookingKey: $id,
                actor: $request->user(),
            );

            $booking = $result['booking'];
            $isEarlyCheckIn = $result['early_check_in'];
            $guestName = $booking->primaryGuest?->name ?? 'Guest';
            $roomNumbers = $booking->bookingRooms->pluck('room.room_number')->filter()->join(', ');
            $actorName = $request->user()?->name ?? 'Receptionist';

            AuditHelper::log(
                actionActivity: $isEarlyCheckIn ? 'Guest Checked In (Admin Approved Early)' : 'Guest Checked In',
                modulePage: 'Check-In Module',
                modelType: 'Booking',
                modelId: (int) $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number . ' — ' . $guestName,
                oldValues: ['booking_status' => 'confirmed'],
                newValues: array_filter([
                    'booking_status' => 'checked_in',
                    'checked_in_by' => $actorName,
                    'checked_in_at' => $booking->checked_in_at?->toDateTimeString(),
                    'early_override' => $isEarlyCheckIn,
                    'early_check_in_request_id' => $result['early_check_in_request_id'],
                    'early_check_in_reason' => $booking->early_check_in_reason,
                ], fn ($value) => $value !== null),
                action: 'updated'
            );

            try {
                $this->notifyStaff(
                    type: NotificationType::UPCOMING_CHECKIN,
                    title: $isEarlyCheckIn ? 'Guest Checked In (Early)' : 'Guest Checked In',
                    message: "Guest {$guestName} (Booking {$booking->reference_number}) checked in to Room {$roomNumbers}. "
                        . ($isEarlyCheckIn ? 'Admin-approved early check-in applied. ' : '')
                        . "Processed by {$actorName}.",
                    data: [
                        'booking_id' => $booking->id,
                        'reference_number' => $booking->reference_number,
                        'guest_name' => $guestName,
                        'room_numbers' => $roomNumbers,
                        'checked_in_by' => $actorName,
                        'early_override' => $isEarlyCheckIn,
                    ]
                );
            } catch (\Throwable $notificationError) {
                Log::warning('Guest check-in notification failed after successful check-in', [
                    'booking_id' => $booking->id,
                    'error' => $notificationError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $isEarlyCheckIn
                    ? 'Guest checked in early using the administrator-approved request.'
                    : 'Guest checked in successfully.',
                'early_check_in' => $isEarlyCheckIn,
                'data' => $booking,
            ]);
        } catch (\RuntimeException $e) {
            $response = $this->checkInErrorResponse($e);
            if ($response !== null) {
                return $response;
            }

            Log::error('Guest check-in failed with an unexpected domain error', [
                'booking_key' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check in guest.',
            ], 500);
        } catch (\Throwable $e) {
            Log::error('Guest check-in failed', [
                'booking_key' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check in guest.',
            ], 500);
        }
    }

    private function checkInErrorResponse(\RuntimeException $exception)
    {
        $message = $exception->getMessage();

        if (str_starts_with($message, 'STATUS_INVALID:')) {
            $status = substr($message, strlen('STATUS_INVALID:'));
            return response()->json([
                'success' => false,
                'message' => "Only confirmed bookings can be checked in. Current status: {$status}.",
            ], 400);
        }

        if ($message === 'PAYMENT_REQUIRED') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot check in. A completed payment must be verified first.',
            ], 409);
        }

        if (str_starts_with($message, 'BALANCE_REQUIRED:')) {
            $remainingBalance = round((float) substr($message, strlen('BALANCE_REQUIRED:')), 2);

            return response()->json([
                'success' => false,
                'code' => 'BALANCE_REQUIRED',
                'message' => 'Collect the remaining balance of ₱' . number_format($remainingBalance, 2) . ' before check-in.',
                'remaining_balance' => $remainingBalance,
            ], 409);
        }

        if (in_array($message, ['ROOM_ASSIGNMENT_INCOMPLETE', 'ROOM_ASSIGNMENT_DUPLICATE'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot check in until every room in this booking has one valid room assignment.',
            ], 409);
        }

        if (str_starts_with($message, 'CHECKIN_DATE_NOT_YET:')) {
            $date = substr($message, strlen('CHECKIN_DATE_NOT_YET:'));
            return response()->json([
                'success' => false,
                'message' => "Cannot check in yet. This booking's check-in date is {$date}.",
            ], 422);
        }

        if (str_starts_with($message, 'CHECKIN_WINDOW_CLOSED:')) {
            $checkOut = substr($message, strlen('CHECKIN_WINDOW_CLOSED:'));
            return response()->json([
                'success' => false,
                'message' => "Cannot check in because this booking's stay ended on {$checkOut}.",
            ], 422);
        }

        if (str_starts_with($message, 'ROOM_NOT_READY:')) {
            $payload = substr($message, strlen('ROOM_NOT_READY:'));
            [$roomNumber, $roomStatus] = array_pad(explode(':', $payload, 2), 2, 'unknown');
            return response()->json([
                'success' => false,
                'message' => "This room is not ready for check-in. Room {$roomNumber} is currently {$roomStatus}.",
            ], 409);
        }

        if (str_starts_with($message, 'ROOM_CONFLICT:')) {
            $reference = substr($message, strlen('ROOM_CONFLICT:'));
            return response()->json([
                'success' => false,
                'message' => "Cannot check in because an assigned room is still occupied by booking {$reference}.",
            ], 409);
        }

        if (str_starts_with($message, 'EARLY_APPROVAL_REQUIRED:')) {
            $checkInTime = substr($message, strlen('EARLY_APPROVAL_REQUIRED:'));
            return response()->json([
                'success' => false,
                'code' => 'EARLY_APPROVAL_REQUIRED',
                'message' => "Check-in starts at {$checkInTime}. Send an early check-in request to an administrator.",
                'check_in_time' => $checkInTime,
                'can_request_approval' => true,
            ], 422);
        }

        if ($message === 'EARLY_APPROVAL_PENDING') {
            return response()->json([
                'success' => false,
                'code' => 'EARLY_APPROVAL_PENDING',
                'message' => 'Early check-in is waiting for administrator approval.',
            ], 409);
        }

        if (str_starts_with($message, 'BOOKING_LIFECYCLE_LOCKED:')) {
            return response()->json([
                'success' => false,
                'message' => 'Check-in is blocked while a cancellation request is in progress for this booking.',
            ], 409);
        }

        return null;
    }

    private function legacyCheckInDeprecated(Request $request, $id)
    {
        $forceEarlyCheckIn = filter_var(
            $request->input('force_early_checkin', false),
            FILTER_VALIDATE_BOOLEAN
        );

        try {
            $booking        = null;
            $isEarlyCheckIn = false;

            DB::transaction(function () use ($id, $forceEarlyCheckIn, &$booking, &$isEarlyCheckIn) {
                $booking = Booking::with(['primaryGuest', 'bookingRooms.room'])
                    ->where('reference_number', $id)
                    ->orWhere('id', $id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Only confirmed bookings may check in
                if ($booking->booking_status !== 'confirmed') {
                    throw new \RuntimeException('STATUS_INVALID:' . $booking->booking_status);
                }

                $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
                    booking: $booking,
                    actionCode: 'receptionist_checkin'
                );

                // ── NEW FIX: Check-in date gate ───────────────────────────────
                // Receptionist cannot check in a guest before the booking's
                // actual check-in date, regardless of payment or confirmation.
                // Example: booking is for Mar 22 — cannot check in on Mar 13.
                $checkInDate = Carbon::parse($booking->check_in)->startOfDay();
                $today       = Carbon::today();

                if ($today->lt($checkInDate)) {
                    throw new \RuntimeException(
                        'CHECKIN_DATE_NOT_YET:' . $checkInDate->format('M d, Y')
                    );
                }
                // ─────────────────────────────────────────────────────────────

                $roomIds = $booking->bookingRooms()->pluck('room_id')->filter()->map(fn ($id) => (int) $id)->all();
                if (empty($roomIds)) {
                    throw new \RuntimeException('NO_ROOMS');
                }

                // RULE 4 — Room readiness check BEFORE time check so the error
                // message is "room not ready" not "too early" when both apply
                $rooms       = \App\Models\Room::whereIn('id', $roomIds)->lockForUpdate()->get();
                $blockedRoom = $rooms->first(
                    fn ($r) => in_array($r->status, self::CHECKIN_BLOCKED_STATUSES, true)
                );
                if ($blockedRoom) {
                    throw new \RuntimeException(
                        'ROOM_NOT_READY:' . $blockedRoom->room_number . ':' . $blockedRoom->status
                    );
                }

                // RULE 1 — Check-in time enforcement (3:00 PM) for overnight stays only.
                // Safety default: unknown/null stay_type is treated as overnight.
                $normalizedStayType = strtolower((string) ($booking->stay_type ?? ''));
                $isOvernight = $normalizedStayType !== 'day_use';

                if ($isOvernight) {
                    $now             = Carbon::now();
                    $officialCheckIn = $now->copy()->setTime(self::CHECK_IN_HOUR, 0, 0);
                    $isEarlyCheckIn  = $now->lt($officialCheckIn);

                    if ($isEarlyCheckIn && !$forceEarlyCheckIn) {
                        throw new \RuntimeException(
                            'EARLY_CHECKIN:' . $officialCheckIn->format('g:i A')
                        );
                    }
                }

                $booking->update([
                    'booking_status'     => 'checked_in',
                    'reservation_status' => 'checked_in',
                ]);

                $this->roomStateService->recalculateMany($roomIds);
            });

            // RULE 8 — Audit trail
            AuditHelper::log(
                actionActivity: $isEarlyCheckIn
                    ? 'Guest Checked In (Early Override)'
                    : 'Guest Checked In',
                modulePage:     'Check-In Module',
                modelType:      'Booking',
                modelId:        $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number
                    . ' — ' . ($booking->primaryGuest?->name ?? ''),
                oldValues:      ['booking_status' => 'confirmed'],
                newValues:      array_filter([
                    'booking_status' => 'checked_in',
                    // 'checked_in_by'  => auth()->user()->name,
                    'check_in_time'  => Carbon::now()->toDateTimeString(),
                    'early_override' => $isEarlyCheckIn ? 'Yes — receptionist override' : null,
                ]),
                action: 'updated'
            );

            $guestName   = $booking->primaryGuest?->name ?? 'Guest';
            $refNumber   = $booking->reference_number;
            $roomNumbers = $booking->bookingRooms->pluck('room.room_number')->join(', ');
            $actorName   = auth()->user()?->name ?? 'Receptionist';

            $this->notifyStaff(
                type:    NotificationType::UPCOMING_CHECKIN,
                title:   $isEarlyCheckIn ? 'Guest Checked In (Early)' : 'Guest Checked In',
                message: "Guest {$guestName} (Booking {$refNumber}) checked in to Room {$roomNumbers}. "
                    . ($isEarlyCheckIn ? 'Early check-in override applied. ' : '')
                    . "Processed by {$actorName}.",
                data:    [
                    'booking_id'       => $booking->id,
                    'reference_number' => $refNumber,
                    'guest_name'       => $guestName,
                    'room_numbers'     => $roomNumbers,
                    'checked_in_by'    => $actorName,
                    'early_override'   => $isEarlyCheckIn,
                ]
            );

            $booking->load(['bookingRooms.room', 'primaryGuest', 'creator', 'payments']);

            return response()->json([
                'success'        => true,
                'message'        => $isEarlyCheckIn
                    ? 'Guest checked in early. Override recorded in audit trail.'
                    : 'Guest checked in successfully.',
                'early_check_in' => $isEarlyCheckIn,
                'data'           => $booking,
            ]);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'STATUS_INVALID:')) {
                $status = substr($e->getMessage(), strlen('STATUS_INVALID:'));
                return response()->json([
                    'success' => false,
                    'message' => 'Only confirmed bookings can be checked in. Current status: ' . $status . '.',
                ], 400);
            }

            if ($e->getMessage() === 'NO_ROOMS') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot check-in: no rooms are attached to this booking.',
                ], 400);
            }

            // NEW FIX — Check-in date not yet reached
            if (str_starts_with($e->getMessage(), 'CHECKIN_DATE_NOT_YET:')) {
                $date = substr($e->getMessage(), strlen('CHECKIN_DATE_NOT_YET:'));
                return response()->json([
                    'success' => false,
                    'message' => "Cannot check-in yet. This booking's check-in date is {$date}.",
                ], 422);
            }

            // RULE 4 — Room not ready
            if (str_starts_with($e->getMessage(), 'ROOM_NOT_READY:')) {
                $payload = substr($e->getMessage(), strlen('ROOM_NOT_READY:'));
                [$roomNum, $roomStatus] = array_pad(explode(':', $payload, 2), 2, 'unknown');
                return response()->json([
                    'success' => false,
                    'message' => "This room is not ready for check-in. Room {$roomNum} is currently {$roomStatus}.",
                ], 409);
            }

            // RULE 1 — Early check-in blocked (no override sent)
            if (str_starts_with($e->getMessage(), 'EARLY_CHECKIN:')) {
                $checkInTime = substr($e->getMessage(), strlen('EARLY_CHECKIN:'));
                return response()->json([
                    'success'            => false,
                    'code'               => 'EARLY_CHECKIN',
                    'message'            => "Guest cannot check in yet. Check-in starts at {$checkInTime}.",
                    'check_in_time'      => $checkInTime,
                    'can_force_override' => true,
                ], 422);
            }
            if (str_starts_with($e->getMessage(), 'BOOKING_LIFECYCLE_LOCKED:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Check-in is blocked while a cancellation request is in progress for this booking.',
                ], 409);
            }

            throw $e;

        } catch (\Exception $e) {
            Log::error('Guest check-in failed', ['booking_key' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to check in guest.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHECK-OUT
    // RULE 6 — Guest must be checked_in; blocked if not.
    // RULE 5 — Room transitions to CLEANING after checkout.
    // ─────────────────────────────────────────────────────────────────────────

    public function checkOut($id)
    {
        try {
            $booking          = null;
            $remainingBalance = 0.0;
            $replayed         = false;

            DB::transaction(function () use ($id, &$booking, &$remainingBalance, &$replayed) {
                $booking = Booking::with(['primaryGuest', 'bookingRooms.room', 'payments'])
                    ->where('reference_number', $id)
                    ->orWhere('id', $id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($booking->booking_status === 'checked_out') {
                    $replayed = true;
                    return;
                }

                // RULE 6 — Must be checked_in to check out
                $remainingBalance = (float) $booking->remaining_balance;
                $booking = $this->checkoutStayService->checkoutBooking(
                    booking: $booking,
                    actorId: (int) auth()->id(),
                );
            });

            // RULE 8 — Audit trail with checkout timestamp
            try {
                if (! $replayed) {
                    AuditHelper::log(
                        actionActivity: 'Guest Checked Out',
                modulePage:     'Check-Out Module',
                modelType:      'Booking',
                modelId:        $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number
                    . ' — ' . ($booking->primaryGuest?->name ?? ''),
                oldValues:      ['booking_status' => 'checked_in'],
                newValues:      [
                    'booking_status'  => 'checked_out',
                    'checked_out_by'  => auth()->user()?->name ?? 'Receptionist',
                    'checked_out_at'  => $booking->checked_out_at?->toDateTimeString(),
                            'room_status_set' => 'cleaning',
                        ],
                        action: 'updated'
                    );
                }
            } catch (\Throwable $e) {
                $this->logPostCommitFailure('checkout_audit', $booking, $e);
            }

            $guestName   = $booking->primaryGuest?->name ?? 'Guest';
            $refNumber   = $booking->reference_number;
            $roomNumbers = $booking->bookingRooms->pluck('room.room_number')->join(', ');
            $actorName   = auth()->user()?->name ?? 'Receptionist';

            try {
                if (! $replayed) {
                    $this->notifyStaff(
                        type:    NotificationType::UPCOMING_CHECKOUT,
                        title:   'Guest Checked Out',
                        message: "Guest {$guestName} (Booking {$refNumber}) checked out from Room {$roomNumbers}. "
                            . "Room set to cleaning. Processed by {$actorName}.",
                        data:    [
                            'booking_id'       => $booking->id,
                            'reference_number' => $refNumber,
                            'guest_name'       => $guestName,
                            'room_numbers'     => $roomNumbers,
                            'checked_out_by'   => $actorName,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                $this->logPostCommitFailure('checkout_notification', $booking, $e);
            }

            $booking->load(['bookingRooms.room', 'primaryGuest', 'creator', 'payments']);

            // Send feedback request email to guest after checkout
            try {
                if (! $replayed) {
                    FeedbackController::createForBooking($booking);
                }
            } catch (\Throwable $e) {
                Log::error(
                    'Feedback email failed after checkout — booking: '
                    . $booking->reference_number
                    . ' — '
                    . $e->getMessage(),
                    [
                        'booking_reference' => $booking->reference_number,
                        'booking_id' => $booking->id,
                        'exception_class' => $e::class,
                        'trace' => $e->getTraceAsString(),
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => $replayed
                    ? 'Guest was already checked out.'
                    : 'Guest checked out successfully. Room has been set to cleaning.',
                'replayed' => $replayed,
                'data'    => $booking,
            ]);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'STATUS_INVALID:')) {
                $status = substr($e->getMessage(), strlen('STATUS_INVALID:'));
                return response()->json([
                    'success' => false,
                    'message' => 'Only checked-in guests can be checked out. Current status: ' . $status . '.',
                ], 400);
            }

            if (str_starts_with($e->getMessage(), 'BALANCE_DUE:')) {
                return response()->json([
                    'success'           => false,
                    'message'           => 'Checkout blocked: remaining balance must be settled first.',
                    'remaining_balance' => round($remainingBalance, 2),
                ], 422);
            }
            if (str_starts_with($e->getMessage(), 'BOOKING_LIFECYCLE_LOCKED:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Check-out is blocked while a cancellation request is in progress for this booking.',
                ], 409);
            }
            if ($e->getMessage() === 'NO_ACTIVE_ROOMS') {
                return response()->json([
                    'success' => false,
                    'message' => 'Checkout could not continue because this booking has no active assigned rooms.',
                ], 409);
            }

            throw $e;

        } catch (QueryException $e) {
            Log::error('Guest check-out failed: database unavailable', [
                'booking_key' => $id,
                'error' => $e->getMessage(),
                'sql_state' => $e->getCode(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Database temporarily unavailable — please try again.',
            ], 503);

        } catch (\Exception $e) {
            Log::error('Guest check-out failed', ['booking_key' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to check out guest.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────────────────────────────────

    public function getStats()
    {
        try {
            $financials = $this->financialReporting->summary();

            return response()->json([
                'success' => true,
                'data'    => [
                    'total_bookings'     => Booking::visibleToStaff()->count(),
                    'pending_bookings'   => Booking::visibleToStaff()->where('booking_status', 'pending')->count(),
                    'confirmed_bookings' => Booking::visibleToStaff()->where('booking_status', 'confirmed')->count(),
                    'checked_in'         => Booking::visibleToStaff()->where('booking_status', 'checked_in')->count(),
                    'checked_out'        => Booking::visibleToStaff()->where('booking_status', 'checked_out')->count(),
                    'cancelled_bookings' => Booking::visibleToStaff()->where('booking_status', 'cancelled')->count(),
                    'gross_revenue'      => $financials['gross_revenue'],
                    'total_refunds'      => $financials['total_refunds'],
                    'total_revenue'      => $financials['net_revenue'],
                    'today_check_ins'  => Booking::visibleToStaff()->whereDate('check_in', today())
                        ->where('booking_status', 'confirmed')
                        ->count(),
                    'today_check_outs' => Booking::visibleToStaff()->whereDate('check_out', today())
                        ->where('booking_status', 'checked_in')
                        ->count(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch receptionist booking stats', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SETTLE BALANCE
    // ─────────────────────────────────────────────────────────────────────────

    public function settleBalance(Request $request, $id)
    {
        $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,gcash',
            'notes'          => 'required|string|max:500',
            'idempotency_key' => 'required|uuid',
            'gcash_reference' => 'required_if:payment_method,gcash|nullable|string|min:6|max:80',
            'gcash_sender_name' => 'required_if:payment_method,gcash|nullable|string|min:2|max:120',
            'gcash_paid_at' => 'required_if:payment_method,gcash|nullable|date|before_or_equal:now',
            'merchant_record_confirmed' => 'exclude_unless:payment_method,gcash|required|accepted',
        ]);

        $gcashSubmission = null;
        $transactionCommitted = false;
        try {
            $amount = round((float) $request->amount, 2);
            DB::beginTransaction();

            $booking = Booking::where(function ($q) use ($id) {
                    $q->where('reference_number', $id)->orWhere('id', $id);
                })
                ->lockForUpdate()
                ->firstOrFail();

            $existingPayment = Payment::where('staff_recording_key', $request->idempotency_key)
                ->lockForUpdate()
                ->first();
            if ($existingPayment) {
                if (
                    (int) $existingPayment->booking_id !== (int) $booking->id
                    || abs((float) $existingPayment->amount - $amount) > 0.009
                    || $existingPayment->payment_method !== $request->payment_method
                ) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This payment request key was already used for different payment details.',
                    ]);
                }

                DB::rollBack();
                $booking->load(['primaryGuest', 'bookingRooms.room', 'payments']);

                return $this->balancePaymentResponse($booking, $existingPayment, true);
            }

            if (!in_array($booking->booking_status, ['confirmed', 'checked_in'], true)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Balance can only be recorded for confirmed or checked-in bookings.',
                ], 400);
            }

            try {
                $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
                    booking: $booking,
                    actionCode: 'receptionist_settle_balance'
                );
            } catch (\RuntimeException $e) {
                if (str_starts_with($e->getMessage(), 'BOOKING_LIFECYCLE_LOCKED:')) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Balance settlement is blocked while a cancellation request is in progress for this booking.',
                    ], 409);
                }
                throw $e;
            }

            $remainingBalance = (float) $booking->remaining_balance;
            if ($remainingBalance <= 0.009) {
                DB::rollBack();
                return response()->json([
                    'success'           => false,
                    'message'           => 'No remaining balance to settle.',
                    'remaining_balance' => 0,
                ], 400);
            }

            if ($amount - $remainingBalance > 0.009) {
                DB::rollBack();
                return response()->json([
                    'success'           => false,
                    'message'           => 'Amount cannot exceed remaining balance.',
                    'remaining_balance' => round($remainingBalance, 2),
                ], 422);
            }

            $canonicalPaymentMethod = Payment::normalizePaymentMethod((string) $request->payment_method, 'manual');
            $paidAt = $canonicalPaymentMethod === 'gcash'
                ? Carbon::parse((string) $request->gcash_paid_at)
                : now();

            $payment = Payment::create([
                'booking_id'            => $booking->id,
                'amount'                => $amount,
                'payment_type'          => Payment::TYPE_BALANCE_PAYMENT,
                'payment_method'        => $canonicalPaymentMethod,
                'payment_status'        => 'completed',
                'transaction_reference' => $canonicalPaymentMethod === 'gcash'
                    ? trim((string) $request->gcash_reference)
                    : null,
                'paid_at'               => $paidAt,
                'verified_by'           => auth()->id(),
                'verified_at'           => now(),
                'notes'                 => trim((string) $request->notes),
                'provider'              => $canonicalPaymentMethod === 'gcash' ? 'manual_gcash' : 'manual',
                'paid_amount'           => $amount,
                'staff_recording_key'   => $request->idempotency_key,
            ]);

            $this->roomAssignmentService->assignRoomToBooking(
                booking: $booking,
                actorLabel: auth()->user()?->name ?? 'Receptionist',
                allowHistoricalFallback: in_array((string) $booking->booking_status, ['checked_in', 'checked_out'], true)
            );

            if ($canonicalPaymentMethod === 'gcash') {
                $gcashSubmission = $this->frontDeskGcashPayments->recordApprovedSubmission(
                    payment: $payment,
                    booking: $booking,
                    staff: auth()->user(),
                    reference: (string) $request->gcash_reference,
                    senderName: (string) $request->gcash_sender_name,
                    paidAt: $paidAt
                );
            }

            $booking->refresh();
            DB::commit();
            $transactionCommitted = true;

            $booking->load(['primaryGuest', 'bookingRooms.room', 'payments']);
            $newRemaining = round((float) $booking->remaining_balance, 2);
            $actorName    = auth()->user()->name ?? 'Receptionist';

            try {
                AuditHelper::log(
                actionActivity: 'Remaining Balance Settled',
                modulePage:     'Payment Module',
                modelType:      'Payment',
                modelId:        $payment->id,
                recordAffected: 'Payment #' . $payment->id . ' — Booking ' . $booking->reference_number,
                oldValues:      ['remaining_balance' => round($remainingBalance, 2)],
                newValues:      [
                    'amount'            => $amount,
                    'payment_method'    => $canonicalPaymentMethod,
                    'notes'             => trim((string) $request->notes),
                    'remaining_balance' => $newRemaining,
                    'processed_by'      => $actorName,
                ],
                    action: 'created'
                );
            } catch (\Throwable $e) {
                $this->logPostCommitFailure('balance_settlement_audit', $booking, $e);
            }

            $guestName   = $booking->primaryGuest?->name ?? 'Guest';
            try {
                $this->notifyStaff(
                    type:    'payment_received',
                    title:   'Balance Payment Received',
                    message: "Remaining balance payment recorded for booking {$booking->reference_number} ({$guestName}) by {$actorName}.",
                    data:    [
                        'booking_id'        => $booking->id,
                        'reference_number'  => $booking->reference_number,
                        'guest_name'        => $guestName,
                        'amount'            => $amount,
                        'remaining_balance' => $newRemaining,
                    ]
                );
            } catch (\Throwable $e) {
                $this->logPostCommitFailure('balance_settlement_notification', $booking, $e);
            }

            return $this->balancePaymentResponse($booking, $payment);

        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (! $transactionCommitted) {
                $this->frontDeskGcashPayments->deleteEvidence($gcashSubmission);
            }

            throw $e;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (! $transactionCommitted) {
                $this->frontDeskGcashPayments->deleteEvidence($gcashSubmission);
            }
            Log::error('Failed to record balance payment', [
                'booking_key' => $id, 'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to record balance payment',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function appendRoomSummary(Booking $booking): Booking
    {
        $rooms = collect($booking->bookingRooms ?? [])
            ->map(function ($line) {
                $room = $line->room;
                if (!$room) {
                    return null;
                }

                $roomType = trim((string) ($room->room_type ?? ''));
                $roomNum  = trim((string) ($room->room_number ?? ''));
                $display  = trim($roomType . ' ' . $roomNum);

                return [
                    'booking_room_id' => (int) $line->id,
                    'room_number'     => $roomNum !== '' ? $roomNum : null,
                    'room_type'       => $roomType !== '' ? $roomType : null,
                    'display'         => $display !== '' ? $display : 'N/A',
                ];
            })
            ->filter()
            ->values();

        $assignedRoom = $rooms
            ->pluck('display')
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->implode(', ');

        $booking->setAttribute('rooms', $rooms->all());
        $booking->setAttribute('room_count', $rooms->count());
        $booking->setAttribute('assigned_room', $assignedRoom !== '' ? $assignedRoom : 'N/A');
        $booking->setAttribute('total_paid', (float) $booking->total_paid);
        $booking->setAttribute('remaining_balance', (float) $booking->remaining_balance);

        return $booking;
    }

    private function balancePaymentResponse(Booking $booking, Payment $payment, bool $replayed = false)
    {
        $booking->loadMissing(['payments']);
        $newRemaining = round((float) $booking->remaining_balance, 2);

        return response()->json([
            'success' => true,
            'message' => $replayed
                ? 'Payment was already recorded.'
                : ($newRemaining > 0.009 ? 'Partial balance payment recorded.' : 'Remaining balance fully settled.'),
            'data' => [
                'payment_id' => $payment->id,
                'amount_paid' => (float) $payment->amount,
                'total_amount' => (float) $booking->total_amount,
                'total_paid' => (float) $booking->total_paid,
                'remaining_balance' => $newRemaining,
                'replayed' => $replayed,
            ],
        ]);
    }

    private function notifyStaff(NotificationType|string $type, string $title, string $message, array $data = []): void
    {
        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            NotificationHelper::send($admin->id, $type, $title, $message, $data);
        }
    }

    private function logPostCommitFailure(string $effect, Booking $booking, \Throwable $exception): void
    {
        Log::error('Post-commit checkout effect failed', [
            'effect' => $effect,
            'booking_id' => $booking->id,
            'booking_reference' => $booking->reference_number,
            'exception_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }
}
