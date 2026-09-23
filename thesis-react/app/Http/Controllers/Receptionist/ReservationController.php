<?php

namespace App\Http\Controllers\Receptionist;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use App\Services\RoomPricingService;
use App\Services\RoomStateService;
use App\Services\TaxService;
use App\Services\RoomTransferRequestService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Helpers\AuditHelper;
use App\Models\BookingCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function __construct(
        private RoomStateService $roomStateService,
        private RoomPricingService $roomPricingService,
        private TaxService $taxService,
        private RoomTransferRequestService $roomTransferRequestService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = Booking::visibleToStaff()
            ->with(['primaryGuest', 'bookingRooms.room', 'payments', 'promoCode']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                  ->orWhereHas('primaryGuest', function ($guestQuery) use ($search) {
                      $guestQuery->where('name',  'like', "%{$search}%")
                                 ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('bookingRooms.room', function ($roomQuery) use ($search) {
                      $roomQuery->where('room_number', 'like', "%{$search}%")
                          ->orWhere('room_type', 'like', "%{$search}%");
                  })
                  ->orWhereHas('bookingRooms', function ($roomLineQuery) use ($search) {
                      $roomLineQuery->where('requested_room_type', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('status') && $request->status !== 'All') {
            $status = strtolower(str_replace([' ', '-'], '_', $request->status));
            $query->where('booking_status', $status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('check_in', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('check_out', '<=', $request->date_to);
        }

        $reservations = $query->latest()->get()->map(function ($booking) {
            return $this->formatBooking($booking);
        });

        return response()->json([
            'reservations' => $reservations,
            'total'        => $reservations->count(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHOW
    // ─────────────────────────────────────────────────────────────────────────

    public function show($referenceNumber)
    {
        $booking = Booking::visibleToStaff()
            ->with(['primaryGuest', 'bookingRooms.room', 'payments', 'promoCode'])
            ->where('reference_number', $referenceNumber)
            ->firstOrFail();

        AuditHelper::log(
            actionActivity: 'Reservation Viewed',
            modulePage: 'Reservation Module',
            modelType: 'Booking',
            modelId: (int) $booking->id,
            recordAffected: 'Booking #' . $booking->reference_number,
            oldValues: null,
            newValues: [
                'viewed_by' => auth()->user()->name ?? 'Receptionist',
                'booking_status' => $booking->booking_status,
            ],
            action: 'viewed'
        );

        return response()->json($this->formatBooking($booking, true));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED FORMAT HELPER
    // ─────────────────────────────────────────────────────────────────────────

    private function formatBooking(Booking $booking, bool $full = false): array
    {
        $primaryGuest     = $booking->primaryGuest;
        $firstRoom        = $booking->bookingRooms->first();
        $totalPaid        = (float) $booking->total_paid;
        $remainingBalance = (float) $booking->remaining_balance;
        $hasVerifiedPayment = $booking->payments->contains(
            fn ($p) => $p->payment_status === 'completed'
        );

        $bookingStatusKey = (string) $booking->booking_status;
        $isPendingLabelAllowed = in_array($bookingStatusKey, ['pending', 'confirmed'], true);
        $hasPendingAssignmentLines = $booking->bookingRooms->contains(fn ($line) => empty($line->room_id));
        $effectiveAssignmentStatus = $hasPendingAssignmentLines ? 'pending_assignment' : 'assigned';

        $rooms = $booking->bookingRooms->map(function ($br) use ($isPendingLabelAllowed) {
            $typeLabel = $this->formatRoomTypeLabel((string) ($br->room?->room_type ?? $br->requested_room_type));

            if (!empty($br->room_id) && !empty($br->room?->room_number)) {
                return trim($typeLabel . ' ' . $br->room->room_number);
            }

            if ($isPendingLabelAllowed && empty($br->room_id)) {
                return $typeLabel . ' (Room Pending)';
            }

            return $typeLabel;
        })->implode(', ');

        $stayType  = $booking->stay_type ?? ($booking->is_day_tour ? 'day_use' : 'overnight');
        $isDayUse  = $stayType === 'day_use';

        // Check-in / check-out display:
        // Now that columns are DATETIME, format them properly.
        // For day_use: check_out stored as check_in + duration — show as-is.
        $checkInFormatted  = $booking->check_in->format('Y-m-d H:i');
        $checkOutFormatted = $booking->check_out->format('Y-m-d H:i');

        // For table/display: date-only portion
        $checkInDate  = $booking->check_in->format('Y-m-d');
        $checkOutDate = $booking->check_out->format('Y-m-d');

        // Duration hours
        $durationHours = $booking->duration_hours
            ?? round($booking->check_in->diffInMinutes($booking->check_out) / 60, 2);

        $nights = $isDayUse ? 1 : ($firstRoom->nights ?? $booking->check_in->diffInDays($booking->check_out));

        $bookingRooms = $booking->bookingRooms->map(function ($line) use ($booking) {
            $room = $line->room;

            $amenities = $room?->amenities ?? [];
            if (is_string($amenities)) {
                $amenities = json_decode($amenities, true) ?? [];
            }
            if (!is_array($amenities)) {
                $amenities = [];
            }

            return [
                'booking_room_id' => (int) $line->id,
                'room_id' => $line->room_id ? (int) $line->room_id : null,
                'room' => [
                    'room_number' => !empty($line->room_id) ? $room?->room_number : null,
                    'room_type' => $room?->room_type ?? $line->requested_room_type,
                    'floor' => $room?->floor,
                    'bed_type' => $this->deriveBedType($room?->room_type ?? $line->requested_room_type),
                    'description' => $room?->description,
                    'amenities' => array_values($amenities),
                ],
                'price_per_night' => round((float) $line->price_per_night, 2),
                'nights' => (int) $line->nights,
                'subtotal' => round((float) $line->subtotal, 2),
                'addons' => $this->resolveRoomAddons($booking, $line->room_id ? (int) $line->room_id : null, (int) $line->id),
            ];
        })->values()->all();

        $data = [
            'id'                 => $booking->reference_number,
            'guest'              => $primaryGuest->name  ?? 'N/A',
            'phone'              => $primaryGuest->phone ?? 'N/A',
            'email'              => $primaryGuest->email ?? 'N/A',
            'room'               => $rooms ?: 'N/A',
            'room_count'         => $booking->bookingRooms->count(),
            'checkIn'            => $checkInDate,
            'checkOut'           => $checkOutDate,
            'checkInFull'        => $checkInFormatted,
            'checkOutFull'       => $checkOutFormatted,
            'nights'             => $nights,
            'durationHours'      => $durationHours,
            'amount'             => '₱' . number_format((float) $booking->total_amount, 2),
            'totalAmount'        => round((float) $booking->total_amount, 2),
            'totalPaid'          => round($totalPaid, 2),
            'remainingBalance'   => round($remainingBalance, 2),
            'status'             => $this->formatStatus($booking->booking_status),
            'paymentStatus'      => $this->getPaymentSummaryStatus($booking),
            'hasVerifiedPayment' => $hasVerifiedPayment,
            'bookingSource'      => $booking->booking_source ?? 'online',
            'stayType'           => $stayType,
            'isDayTour'          => $isDayUse,  // kept for backward compat
            'dayTourStartTime'   => $booking->day_tour_start_time,
            'dayTourEndTime'     => $booking->day_tour_end_time,
            'promoCode'          => $booking->promoCode?->code ?? null,
            'discountAmount'     => round((float) ($booking->discount_amount ?? 0), 2),
            'addons_breakdown'   => $booking->addons_breakdown ?? [],
            'bookingRooms'       => $bookingRooms,
            'roomAssignmentStatus' => $effectiveAssignmentStatus,
            'roomAssignedAt' => optional($booking->room_assigned_at)->toIso8601String(),
        ];

        if ($full) {
            $data['created_at'] = $booking->created_at->format('Y-m-d H:i');
        }

        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHECK-IN
    // ─────────────────────────────────────────────────────────────────────────

    private function legacyCheckInDeprecated(Request $request, $referenceNumber)
    {
        $forceEarlyCheckIn = filter_var(
            $request->input('force_early_checkin', false),
            FILTER_VALIDATE_BOOLEAN
        );

        try {
            $booking         = null;
            $conflictPayload = null;
            $isEarlyCheckIn  = false;

            DB::transaction(function () use ($referenceNumber, $forceEarlyCheckIn, &$booking, &$conflictPayload, &$isEarlyCheckIn) {
                $booking = Booking::with(['bookingRooms.room', 'payments'])
                    ->where('reference_number', $referenceNumber)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($booking->booking_status !== 'confirmed') {
                    throw new \RuntimeException('STATUS_INVALID:' . $booking->booking_status);
                }

                if (!$this->hasVerifiedPayment($booking)) {
                    throw new \RuntimeException('PAYMENT_REQUIRED');
                }

                $checkInDate = Carbon::parse($booking->check_in)->startOfDay();
                $today       = Carbon::today();

                if ($today->lt($checkInDate)) {
                    throw new \RuntimeException('CHECKIN_DATE_NOT_YET:' . $checkInDate->format('M d, Y'));
                }

                $roomIds = $booking->bookingRooms()->pluck('room_id')->filter()->map(fn ($id) => (int) $id)->all();
                if (empty($roomIds)) throw new \RuntimeException('NO_ROOMS');

                $rooms       = Room::whereIn('id', $roomIds)->lockForUpdate()->get();
                $blockedRoom = $rooms->first(
                    fn ($r) => in_array($r->status, BookingController::CHECKIN_BLOCKED_STATUSES, true)
                );
                if ($blockedRoom) {
                    throw new \RuntimeException('ROOM_NOT_READY:' . $blockedRoom->room_number . ':' . $blockedRoom->status);
                }

                $roomIdCollection = $booking->bookingRooms()
                    ->active()
                    ->pluck('room_id')
                    ->filter()
                    ->values();
                $conflictingBooking = Booking::where('booking_status', 'checked_in')
                    ->where('id', '!=', $booking->id)
                    ->whereHas('bookingRooms', fn ($q) => $q->active()->whereIn('room_id', $roomIdCollection))
                    ->with('primaryGuest')
                    ->first();

                if ($conflictingBooking) {
                    $conflictPayload = [
                        'guest' => $conflictingBooking->primaryGuest->name ?? 'another guest',
                        'ref'   => $conflictingBooking->reference_number,
                        'out'   => Carbon::parse($conflictingBooking->check_out)->format('Y-m-d H:i'),
                    ];
                    throw new \RuntimeException('ROOM_CONFLICT');
                }

                // 3PM rule — only for online reservations; walk-ins are always exempt
                $isWalkIn = $booking->booking_source === 'walk_in';
                $now      = Carbon::now();
                $officialCheckIn = $now->copy()->setTime(BookingController::CHECK_IN_HOUR, 0, 0);
                $isEarlyCheckIn  = $now->lt($officialCheckIn);

                if ($isEarlyCheckIn && !$forceEarlyCheckIn && !$isWalkIn) {
                    throw new \RuntimeException('EARLY_CHECKIN:' . $officialCheckIn->format('g:i A'));
                }

                if ($isWalkIn) $isEarlyCheckIn = false;

                $booking->update([
                    'booking_status'     => 'checked_in',
                    'reservation_status' => 'checked_in',
                ]);

                $this->roomStateService->recalculateMany($roomIds);
            });

            AuditHelper::log(
                actionActivity: $isEarlyCheckIn
                    ? 'Guest Checked In — Early Override (Reservation)'
                    : 'Guest Checked In (Reservation)',
                modulePage:     'Reservation Module',
                modelType:      'Booking',
                modelId:        $booking->id,
                recordAffected: 'Booking #' . $booking->reference_number,
                oldValues:      ['status' => 'confirmed'],
                newValues:      array_filter([
                    'status'         => 'checked_in',
                    'check_in_time'  => Carbon::now()->toDateTimeString(),
                    'early_override' => $isEarlyCheckIn ? 'Yes — receptionist override' : null,
                ]),
                action: 'updated'
            );

            return response()->json([
                'success'        => true,
                'message'        => $isEarlyCheckIn
                    ? 'Guest checked in early. Override recorded in audit trail.'
                    : 'Guest checked in successfully.',
                'early_check_in' => $isEarlyCheckIn,
                'booking'        => $booking,
            ]);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'STATUS_INVALID:')) {
                return response()->json(['success' => false, 'message' => 'Cannot check-in this booking. Current status: ' . substr($e->getMessage(), 13) . '.'], 400);
            }
            if ($e->getMessage() === 'PAYMENT_REQUIRED') {
                return response()->json(['success' => false, 'message' => 'Cannot check-in. Payment must be verified first.'], 400);
            }
            if ($e->getMessage() === 'NO_ROOMS') {
                return response()->json(['success' => false, 'message' => 'Cannot check-in: no rooms are attached to this booking.'], 400);
            }
            if (str_starts_with($e->getMessage(), 'CHECKIN_DATE_NOT_YET:')) {
                $date = substr($e->getMessage(), strlen('CHECKIN_DATE_NOT_YET:'));
                return response()->json(['success' => false, 'message' => "Cannot check-in yet. This booking's check-in date is {$date}."], 422);
            }
            if (str_starts_with($e->getMessage(), 'ROOM_NOT_READY:')) {
                $payload = substr($e->getMessage(), strlen('ROOM_NOT_READY:'));
                [$roomNum, $roomStatus] = array_pad(explode(':', $payload, 2), 2, 'unknown');
                return response()->json(['success' => false, 'message' => "This room is not ready for check-in. Room {$roomNum} is currently {$roomStatus}."], 409);
            }
            if (str_starts_with($e->getMessage(), 'EARLY_CHECKIN:')) {
                $checkInTime = substr($e->getMessage(), strlen('EARLY_CHECKIN:'));
                return response()->json(['success' => false, 'code' => 'EARLY_CHECKIN', 'message' => "Guest cannot check in yet. Check-in starts at {$checkInTime}.", 'check_in_time' => $checkInTime, 'can_force_override' => true], 422);
            }
            if ($e->getMessage() === 'ROOM_CONFLICT' && is_array($conflictPayload)) {
                return response()->json(['success' => false, 'message' => "Cannot check-in. One or more rooms are occupied by booking {$conflictPayload['ref']} ({$conflictPayload['guest']}), checking out on {$conflictPayload['out']}."], 409);
            }
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHECK-OUT
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // TRANSFER ROOMS
    // ─────────────────────────────────────────────────────────────────────────

    public function getTransferRooms($referenceNumber)
    {
        $booking = Booking::with(['bookingRooms.room'])
            ->where('reference_number', $referenceNumber)
            ->firstOrFail();

        if ($booking->booking_status !== 'checked_in') {
            return response()->json(['success' => false, 'message' => 'Room transfer is only allowed for checked-in bookings.'], 400);
        }

        $requestedBookingRoomId = request()->query('booking_room_id');
        if ($requestedBookingRoomId !== null && !is_numeric($requestedBookingRoomId)) {
            return response()->json(['success' => false, 'message' => 'Invalid booking room selection.'], 422);
        }

        $bookingRooms = $booking->bookingRooms->values();
        if ($bookingRooms->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Room assignment is missing for this booking.'], 400);
        }

        if ($requestedBookingRoomId === null && $bookingRooms->count() > 1) {
            return response()->json([
                'success' => false,
                'code' => 'BOOKING_ROOM_REQUIRED',
                'message' => 'Please select which room line to transfer for this booking.',
                'booking_rooms' => $bookingRooms->map(fn ($line) => [
                    'booking_room_id' => (int) $line->id,
                    'room_id' => (int) $line->room_id,
                    'room_number' => $line->room?->room_number,
                    'room_type' => $line->room?->room_type,
                ])->values(),
            ], 422);
        }

        $currentBookingRoom = $requestedBookingRoomId !== null
            ? $bookingRooms->first(fn ($line) => (int) $line->id === (int) $requestedBookingRoomId)
            : $bookingRooms->first();

        if (!$currentBookingRoom) {
            return response()->json(['success' => false, 'message' => 'Selected room line does not belong to this booking.'], 422);
        }

        $currentRoom        = $currentBookingRoom->room;

        $otherAssignedRoomIds = $bookingRooms
            ->where('id', '!=', $currentBookingRoom->id)
            ->pluck('room_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $candidateRoomIds = Room::where('capacity', '>=', $booking->number_of_guests)
            ->whereNotIn('status', ['maintenance', 'cleaning', 'occupied'])
            ->where('id', '!=', $currentRoom->id)
            ->when(! empty($otherAssignedRoomIds), fn ($query) => $query->whereNotIn('id', $otherAssignedRoomIds))
            ->pluck('id');

        $blockedRoomIds = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->where(function ($activeQ) {
                $activeQ->whereNull('booking_rooms.room_status')
                    ->orWhere('booking_rooms.room_status', 'active');
            })
            ->whereIn('bookings.booking_status', ['pending', 'confirmed', 'checked_in'])
            ->where('bookings.id', '!=', $booking->id)
            ->where('bookings.check_in',  '<', $booking->check_out)
            ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$booking->check_in])
            ->pluck('booking_rooms.room_id')
            ->unique();

        $rooms = Room::whereIn('id', $candidateRoomIds)
            ->whereNotIn('id', $blockedRoomIds)
            ->orderBy('room_type')->orderBy('room_number')
            ->get(['id', 'room_number', 'room_type', 'floor', 'capacity', 'price_per_night', 'status']);

        $rooms->transform(function ($room) {
            $room->price_per_night = $this->roomPricingService->resolveNightlyRateForRoom($room);
            return $room;
        });

        return response()->json([
            'success'         => true,
            'selected_booking_room_id' => (int) $currentBookingRoom->id,
            'booking_rooms' => $bookingRooms->map(fn ($line) => [
                'booking_room_id' => (int) $line->id,
                'room_id' => (int) $line->room_id,
                'room_number' => $line->room?->room_number,
                'room_type' => $line->room?->room_type,
            ])->values(),
            'current_room'    => [
                'id'              => $currentRoom->id,
                'room_number'     => $currentRoom->room_number,
                'room_type'       => $currentRoom->room_type,
                'booking_room_id' => (int) $currentBookingRoom->id,
                'price_per_night' => $currentBookingRoom->price_per_night,
            ],
            'available_rooms' => $rooms,
        ]);
    }

    public function transferRoom(Request $request, $referenceNumber)
    {
        $request->validate([
            'booking_room_id' => 'nullable|integer|exists:booking_rooms,id',
            'target_room_id' => 'required|integer|exists:rooms,id',
            'reason'         => 'required|string|max:1000',
        ]);

        try {
            $requestRow = $this->roomTransferRequestService->createRequest(
                bookingKey: $referenceNumber,
                payload: [
                    'booking_room_id' => $request->input('booking_room_id'),
                    'target_room_id' => (int) $request->input('target_room_id'),
                    'reason' => (string) $request->input('reason'),
                ],
                requester: $request->user()
            );
        } catch (\RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'BOOKING_NOT_TRANSFERABLE' => 'Room transfer is only allowed for checked-in bookings.',
                'BOOKING_ROOM_REQUIRED' => 'Please select which room line to transfer for this booking.',
                'OPEN_REQUEST_EXISTS' => 'An open room transfer request already exists for this booking.',
                'TARGET_SAME_AS_CURRENT' => 'Target room is the same as current room.',
                'TARGET_ALREADY_ASSIGNED_TO_BOOKING' => 'Target room is already assigned to another room line in this booking.',
                'TARGET_NOT_AVAILABLE_STATUS' => 'Target room is not available for transfer.',
                'TARGET_CAPACITY_MISMATCH' => 'Target room cannot accommodate this booking.',
                'TARGET_OVERLAP' => 'Target room has an overlapping active booking.',
                default => 'Unable to create room transfer request.',
            };
            $statusCode = match ($e->getMessage()) {
                'OPEN_REQUEST_EXISTS', 'TARGET_OVERLAP', 'TARGET_NOT_AVAILABLE_STATUS', 'TARGET_ALREADY_ASSIGNED_TO_BOOKING' => 409,
                default => 422,
            };

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $statusCode);
        }

        return response()->json([
            'success' => true,
            'message' => 'Room transfer request submitted for admin approval.',
            'request' => [
                'id' => 'RTR' . str_pad((string) $requestRow->id, 4, '0', STR_PAD_LEFT),
                'status' => $requestRow->status,
            ],
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXTRA CHARGES
    // ─────────────────────────────────────────────────────────────────────────

    public function addExtraCharges(Request $request, $id)
    {
        $maxLines = max(1, (int) config('addons.extra_charges.max_lines_per_request', 20));
        $maxAmount = max(0.01, (float) config('addons.extra_charges.max_amount_per_line', 100000));
        $categories = (array) config('addons.extra_charges.categories', []);
        $validator = Validator::make($request->all(), [
            'idempotency_key'       => ['required', 'uuid'],
            'charges'               => ['required', 'array', 'min:1', "max:{$maxLines}"],
            'charges.*.description' => ['required', 'string', 'min:3', 'max:255'],
            'charges.*.category'    => ['required', Rule::in($categories)],
            'charges.*.amount'      => ['required', 'numeric', 'min:0.01', "max:{$maxAmount}"],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $validated = $validator->validated();
            $charges = collect($validated['charges'])->map(fn (array $charge) => [
                'description' => trim((string) $charge['description']),
                'category' => trim((string) $charge['category']),
                'amount' => round((float) $charge['amount'], 2),
            ])->values()->all();
            $totalExtra = round((float) collect($charges)->sum('amount'), 2);
            $operationToken = (string) $validated['idempotency_key'];
            $operationHash = hash('sha256', json_encode($charges, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $result = DB::transaction(function () use ($id, $charges, $totalExtra, $operationToken, $operationHash, $request) {
                $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();
                $existingCharges = BookingCharge::where('booking_id', $booking->id)
                    ->where('operation_token', $operationToken)
                    ->orderBy('operation_line')
                    ->lockForUpdate()
                    ->get();

                if ($existingCharges->isNotEmpty()) {
                    $existingHash = (string) $existingCharges->first()->operation_hash;
                    if ($existingHash === '' || !hash_equals($existingHash, $operationHash)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'This charge request key was already used for different charge details.',
                        ]);
                    }

                    return [
                        'booking' => $booking,
                        'duplicate' => true,
                        'old_remaining_balance' => (float) $booking->remaining_balance,
                    ];
                }

                if ($booking->booking_status !== 'checked_in') {
                    return [
                        'booking' => $booking,
                        'duplicate' => false,
                        'error' => 'Extra charges can only be added after the guest has checked in.',
                        'status' => 409,
                    ];
                }

                $oldRemainingBalance = (float) $booking->remaining_balance;

                foreach ($charges as $lineIndex => $charge) {
                    BookingCharge::create([
                        'booking_id' => $booking->id,
                        'label' => $charge['description'],
                        'category' => $charge['category'],
                        'amount' => $charge['amount'],
                        'created_by' => $request->user()->id,
                        'operation_token' => $operationToken,
                        'operation_line' => $lineIndex,
                        'operation_hash' => $operationHash,
                    ]);
                }

                $booking->total_amount = round((float) $booking->total_amount + $totalExtra, 2);
                $taxRate = $booking->tax_rate !== null
                    ? (float) $booking->tax_rate
                    : $this->taxService->rate();
                $booking->tax_amount = $this->taxService->calculate((float) $booking->total_amount, $taxRate);
                $booking->tax_rate = $taxRate;
                $booking->save();

                return [
                    'booking' => $booking->fresh(),
                    'duplicate' => false,
                    'old_remaining_balance' => $oldRemainingBalance,
                ];
            }, 3);

            if (isset($result['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'],
                ], $result['status']);
            }

            $booking = $result['booking'];

            if (!$result['duplicate']) {
                AuditHelper::log(
                    actionActivity: 'Extra Charges Added at Check-Out',
                    modulePage:     'Reservation Module',
                    modelType:      'Booking',
                    modelId:        $booking->id,
                    recordAffected: 'Booking #' . $booking->reference_number,
                    oldValues:      ['remaining_balance' => $result['old_remaining_balance']],
                    newValues:      ['charges_added' => $charges, 'total_extra' => $totalExtra, 'new_total_amount' => (float) $booking->total_amount, 'remaining_balance' => (float) $booking->remaining_balance, 'processed_by' => $request->user()->name],
                    action: 'updated'
                );
            }

            return response()->json([
                'success'           => true,
                'duplicate'         => $result['duplicate'],
                'message'           => $result['duplicate']
                    ? 'This extra charge request was already recorded.'
                    : count($charges) . ' extra charge(s) added successfully.',
                'total_amount'      => (float) $booking->total_amount,
                'remaining_balance' => (float) $booking->remaining_balance,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('addExtraCharges failed', ['error' => $e->getMessage(), 'booking_id' => $id]);
            return response()->json(['success' => false, 'message' => 'Failed to add extra charges.'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function hasVerifiedPayment($booking): bool
    {
        return $booking->payments->contains(fn ($p) => $p->payment_status === 'completed');
    }

    private function formatStatus($status): string
    {
        return [
            'pending'     => 'Pending',
            'confirmed'   => 'Confirmed',
            'checked_in'  => 'Checked-In',
            'checked_out' => 'Checked-Out',
            'cancelled'   => 'Cancelled',
            'no_show'     => 'No-Show',
        ][$status] ?? ucfirst($status);
    }

    private function getPaymentSummaryStatus(Booking $booking): string
    {
        $totalAmount = (float) $booking->total_amount;
        $totalPaid   = (float) $booking->total_paid;

        if ($totalPaid <= 0.009) return 'UNPAID';

        if (((float) $booking->remaining_balance) <= 0.009 || ($totalAmount - $totalPaid) <= 0.009) return 'PAID';

        return 'PARTIAL';
    }

    private function resolveRoomAddons(Booking $booking, ?int $roomId, ?int $bookingRoomId = null): array
    {
        $breakdown = $booking->addons_breakdown;
        if (!is_array($breakdown)) {
            return [];
        }

        $roomKeys = array_values(array_unique(array_filter([
            $bookingRoomId !== null ? (string) $bookingRoomId : null,
            $roomId !== null ? (string) $roomId : null,
        ])));

        $roomAddons = [];
        foreach ($roomKeys as $roomKey) {
            if (isset($breakdown[$roomKey]) && is_array($breakdown[$roomKey])) {
                $roomAddons = $breakdown[$roomKey];
                break;
            }
        }
        if (!is_array($roomAddons)) {
            return [];
        }

        return collect($roomAddons)
            ->filter(fn ($addon) => is_array($addon))
            ->map(function (array $addon) {
                $quantity = max(1, (int) ($addon['quantity'] ?? 1));
                $price = round((float) ($addon['price'] ?? 0), 2);

                return [
                    'id' => (string) ($addon['id'] ?? ''),
                    'name' => (string) ($addon['name'] ?? 'Add-on'),
                    'description' => $addon['description'] ?? null,
                    'quantity' => $quantity,
                    'price' => $price,
                    'line_total' => round((float) ($addon['line_total'] ?? ($price * $quantity)), 2),
                ];
            })
            ->values()
            ->all();
    }

    private function formatRoomTypeLabel(?string $roomType): string
    {
        $normalized = trim((string) $roomType);
        if ($normalized === '') {
            return 'Room';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }

    private function deriveBedType(?string $roomType): string
    {
        return match ((string) $roomType) {
            'superior_twin' => 'Twin Beds',
            'superior_queen' => 'Queen Bed',
            'family' => 'Family Bed Setup',
            'executive_suite', 'premier', 'deluxe' => 'King Bed',
            default => 'Standard Bed',
        };
    }
}
