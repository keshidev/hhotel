<?php

namespace App\Http\Controllers\Receptionist;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\Room;
use App\Services\PromoCodeService;
use App\Services\FrontDeskGcashPaymentService;
use App\Services\RoomAssignmentService;
use App\Services\RoomPricingService;
use App\Services\RoomStateService;
use App\Services\TaxService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WalkInController extends Controller
{
    public function __construct(
        private RoomStateService $roomStateService,
        private PromoCodeService $promoCodeService,
        private RoomAssignmentService $roomAssignmentService,
        private RoomPricingService $roomPricingService,
        private TaxService $taxService,
        private FrontDeskGcashPaymentService $frontDeskGcashPayments,
    ) {
    }

    // GET /api/receptionist/rooms/available
    public function availableRooms(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'check_in' => 'required|date',
            'check_out' => 'required|date',
            'number_of_guests' => 'required|integer|min:1|max:20',
            'stay_type' => 'nullable|in:day_use,overnight',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $checkIn = $request->check_in;
        $checkOut = $request->check_out;
        $guests = (int) $request->number_of_guests;
        $todayDate = Carbon::today()->format('Y-m-d');

        $ciCarbon = Carbon::parse($checkIn);
        $coCarbon = Carbon::parse($checkOut);

        if ($message = $this->walkInCheckInError($ciCarbon)) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => ['check_in' => [$message]],
            ], 422);
        }

        $bookedRoomIds = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->where(function ($activeQ) {
                $activeQ->whereNull('booking_rooms.room_status')
                    ->orWhere('booking_rooms.room_status', 'active');
            })
            ->whereIn('bookings.booking_status', ['confirmed', 'checked_in'])
            ->where('bookings.check_in', '<', $coCarbon->toDateTimeString())
            ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$ciCarbon->toDateTimeString()])
            ->whereNotNull('booking_rooms.room_id')
            ->pluck('booking_rooms.room_id')
            ->toArray();

        $roomsQuery = Room::query()
            ->whereNotIn('id', $bookedRoomIds);

        if ($ciCarbon->format('Y-m-d') === $todayDate) {
            $roomsQuery->where('status', 'available');
        } else {
            $roomsQuery->whereNotIn('status', ['maintenance', 'cleaning']);
        }

        $rooms = $roomsQuery->orderBy('room_type')->orderBy('price_per_night')->get();

        $availableRoomIdsByType = $rooms
            ->pluck('room_type')
            ->unique()
            ->mapWithKeys(function ($roomType) use ($ciCarbon, $coCarbon) {
                return [
                    (string) $roomType => $this->roomAssignmentService
                        ->getAvailableRoomsByType(
                            roomType: (string) $roomType,
                            checkIn: $ciCarbon,
                            checkOut: $coCarbon
                        )
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all(),
                ];
            });

        $availableCountsByType = $rooms
            ->pluck('room_type')
            ->unique()
            ->mapWithKeys(function ($roomType) use ($ciCarbon, $coCarbon) {
                return [
                    (string) $roomType => $this->roomAssignmentService->countAvailableRoomsByType(
                        roomType: (string) $roomType,
                        checkIn: $ciCarbon,
                        checkOut: $coCarbon
                    ),
                ];
            });

        $rooms = $rooms
            ->groupBy('room_type')
            ->flatMap(function ($typeRooms, $roomType) use ($availableCountsByType, $availableRoomIdsByType) {
                $availableCount = (int) ($availableCountsByType[$roomType] ?? 0);
                $availableRoomIds = $availableRoomIdsByType[$roomType] ?? [];

                return $typeRooms
                    ->whereIn('id', $availableRoomIds)
                    ->take($availableCount)
                    ->each(fn ($room) => $room->availability_count = $availableCount);
            })
            ->values();

        $rooms->transform(function ($room) {
            $fixedNightlyRate = $this->roomPricingService->resolveNightlyRateForRoom($room);
            $dayUseRate = $this->roomPricingService->resolveDayUseRateForRoom($room);
            $room->price_per_night = $fixedNightlyRate;
            $room->price_day_tour = $dayUseRate;

            $images = is_string($room->images)
                ? (json_decode($room->images, true) ?? [])
                : ($room->images ?? []);

            $room->image_urls = array_values(array_map(function ($p) {
                if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
                    return $p;
                }
                $p = ltrim($p, '/');
                return str_starts_with($p, 'storage/') ? asset($p) : asset('storage/' . $p);
            }, array_filter($images)));

            return $room;
        });

        return response()->json(['success' => true, 'data' => $rooms]);
    }

    // POST /api/receptionist/walk-in
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'guest_name' => 'required|string|min:2|max:255',
            'guest_email' => 'required|email|max:255',
            'guest_phone' => 'required|string|max:30',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'number_of_guests' => 'required|integer|min:1|max:20',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|distinct|exists:rooms,id',
            'payment_method' => 'required|in:cash,gcash',
            'amount_tendered' => 'required|numeric|min:0',
            'payment_reference' => 'required_if:payment_method,gcash|nullable|string|min:6|max:80',
            'gcash_sender_name' => 'required_if:payment_method,gcash|nullable|string|min:2|max:120',
            'gcash_paid_at' => 'required_if:payment_method,gcash|nullable|date|before_or_equal:now',
            'merchant_record_confirmed' => 'exclude_unless:payment_method,gcash|required|accepted',
            'idempotency_key' => 'required|uuid',
            'stay_type' => 'required|in:day_use,overnight',
            'special_requests' => 'nullable|string|max:1000',
            // Kept for backward-compatible payload acceptance; ignored in pricing.
            'price_override' => 'nullable|numeric|min:0',
            'promo_code' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $existingPayment = Payment::where('staff_recording_key', $request->idempotency_key)->first();
        if ($existingPayment) {
            $existingPayment->load('booking.bookingRooms.room', 'booking.primaryGuest', 'booking.payments');

            return $this->walkInResponse($existingPayment->booking, $existingPayment, true);
        }

        $stayType = $request->stay_type;
        $isDayUse = $stayType === 'day_use';
        $checkIn = Carbon::parse($request->check_in);
        $checkOut = Carbon::parse($request->check_out);
        $roomIds = $request->room_ids;

        if ($message = $this->walkInCheckInError($checkIn)) {
            throw ValidationException::withMessages([
                'check_in' => [$message],
            ]);
        }

        $durationHours = round($checkIn->diffInMinutes($checkOut) / 60, 2);

        if ($isDayUse && $durationHours > 12) {
            return response()->json([
                'success' => false,
                'message' => 'Day use cannot exceed 12 hours. Current duration: ' . $durationHours . ' hours.',
            ], 422);
        }

        $booking = null;
        $changeDue = 0.0;
        $canonicalPaymentMethod = Payment::normalizePaymentMethod((string) $request->payment_method, 'manual');
        $amountTendered = round((float) $request->amount_tendered, 2);
        $paymentReference = trim((string) ($request->payment_reference ?? '')) ?: null;
        $gcashPaidAt = $canonicalPaymentMethod === 'gcash'
            ? Carbon::parse((string) $request->gcash_paid_at)
            : now();
        $payment = null;
        $gcashSubmission = null;
        try {
            DB::transaction(function () use (
                $request,
                $checkIn,
                $checkOut,
                $isDayUse,
                $stayType,
                $durationHours,
                $roomIds,
                &$booking,
                &$canonicalPaymentMethod,
                &$changeDue,
                $amountTendered,
                $paymentReference,
                $gcashPaidAt,
                &$payment,
                &$gcashSubmission
            ) {
                $requestedRooms = Room::query()
                    ->whereIn('id', $roomIds)
                    ->get()
                    ->keyBy('id');

                if ($requestedRooms->count() !== count($roomIds)) {
                    throw new \RuntimeException('ROOM_CONFLICT');
                }

                $lockedRoomsByType = $this->roomAssignmentService->lockRoomTypesForInventory(
                    $requestedRooms->pluck('room_type')->all()
                );
                $lockedRoomsById = collect($lockedRoomsByType)
                    ->flatten(1)
                    ->keyBy('id');
                $rooms = collect($roomIds)
                    ->map(fn ($roomId) => $lockedRoomsById->get((int) $roomId))
                    ->filter()
                    ->values();

                if ($rooms->count() !== count($roomIds)) {
                    throw new \RuntimeException('ROOM_CONFLICT');
                }

                $selectedCapacity = (int) $rooms->sum(fn ($room) => max(0, (int) $room->capacity));
                $guestCount = (int) $request->number_of_guests;

                if ($guestCount > $selectedCapacity) {
                    throw ValidationException::withMessages([
                        'number_of_guests' => "The selected rooms can accommodate only {$selectedCapacity} guest(s). Please select more rooms or reduce the guest count.",
                    ]);
                }

                $conflict = DB::table('booking_rooms')
                    ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
                    ->whereIn('booking_rooms.room_id', $roomIds)
                    ->where(function ($activeQ) {
                        $activeQ->whereNull('booking_rooms.room_status')
                            ->orWhere('booking_rooms.room_status', 'active');
                    })
                    ->whereIn('bookings.booking_status', ['confirmed', 'checked_in'])
                    ->where('bookings.check_in', '<', $checkOut->toDateTimeString())
                    ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$checkIn->toDateTimeString()])
                    ->lockForUpdate()
                    ->exists();

                if ($conflict) {
                    throw new \RuntimeException('ROOM_CONFLICT');
                }

                foreach ($rooms->countBy(fn ($room) => (string) $room->room_type) as $roomType => $requestedCount) {
                    $selectedRoomIds = $rooms
                        ->where('room_type', $roomType)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values();
                    $availableRoomIds = $this->roomAssignmentService
                        ->getAvailableRoomsByType(
                            roomType: (string) $roomType,
                            checkIn: $checkIn,
                            checkOut: $checkOut
                        )
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id);

                    if ($selectedRoomIds->diff($availableRoomIds)->isNotEmpty()) {
                        throw new \RuntimeException('ROOM_CONFLICT');
                    }

                    $availableCount = $this->roomAssignmentService->countAvailableRoomsByType(
                        roomType: (string) $roomType,
                        checkIn: $checkIn,
                        checkOut: $checkOut
                    );

                    if ($availableCount < (int) $requestedCount) {
                        throw new \RuntimeException('ROOM_CONFLICT');
                    }
                }

                $nights = max(1, (int) $checkIn->diffInDays($checkOut));
                if ($isDayUse) {
                    $nights = 1;
                }

                $roomLineSnapshots = [];
                $totalAmount = 0.0;
                foreach ($rooms as $room) {
                    $nightlyRate = $this->roomPricingService->resolveNightlyRateForRoom($room);
                    $rate = $isDayUse
                        ? $this->roomPricingService->resolveDayUseRate((float) ($room->price_day_tour ?? 0), $nightlyRate)
                        : $nightlyRate;
                    $lineSubtotal = round($rate * $nights, 2);
                    $roomLineSnapshots[$room->id] = [
                        'rate' => $rate,
                        'subtotal' => $lineSubtotal,
                    ];
                    $totalAmount += $lineSubtotal;
                }
                $totalAmount = round($totalAmount, 2);

                $promoModel = null;
                $discountAmount = 0.0;
                $discountedSubtotal = $totalAmount;

                if (!empty($request->promo_code)) {
                    $promoResult = $this->promoCodeService->validateForReservation($request->promo_code, [
                        'guest_email' => $request->guest_email,
                        'check_in' => $checkIn->toDateString(),
                        'check_out' => $checkOut->toDateString(),
                        'subtotal' => $totalAmount,
                        'booking_source' => 'walk_in',
                    ]);

                    if (!$promoResult['valid']) {
                        throw ValidationException::withMessages([
                            'promo_code' => $promoResult['message'],
                        ]);
                    }

                    $promoModel = $promoResult['promo'];
                    $discountAmount = $promoResult['discount_amount'];
                    $discountedSubtotal = $promoResult['final_total'];
                }

                $taxBreakdown = $this->taxService->apply($discountedSubtotal);
                $taxAmount = $taxBreakdown['tax_amount'];
                $totalAmount = $taxBreakdown['total'];

                if ($amountTendered + 0.009 < $totalAmount) {
                    throw ValidationException::withMessages([
                        'amount_tendered' => 'Amount tendered must be at least ' . $this->formatCurrency($totalAmount) . '. Please collect the full payment before confirming.',
                    ]);
                }

                if (
                    $canonicalPaymentMethod === 'gcash'
                    && abs($amountTendered - $totalAmount) > 0.009
                ) {
                    throw ValidationException::withMessages([
                        'amount_tendered' => 'GCash payments must exactly match the booking total of ' . $this->formatCurrency($totalAmount) . '.',
                    ]);
                }

                if ($canonicalPaymentMethod === 'gcash' && empty($paymentReference)) {
                    throw ValidationException::withMessages([
                        'payment_reference' => 'GCash reference number is required.',
                    ]);
                }

                $changeDue = round(max(0, $amountTendered - $totalAmount), 2);

                $booking = Booking::create([
                    'check_in' => $checkIn->toDateTimeString(),
                    'check_out' => $checkOut->toDateTimeString(),
                    'number_of_guests' => $request->number_of_guests,
                    'booking_status' => 'confirmed',
                    'reservation_status' => 'confirmed',
                    'special_requests' => $request->special_requests,
                    'total_amount' => $totalAmount,
                    'created_by' => auth()->id(),
                    'booking_source' => 'walk_in',
                    'is_day_tour' => $isDayUse,
                    'stay_type' => $stayType,
                    'duration_hours' => $durationHours,
                    'day_tour_start_time' => $isDayUse ? $checkIn->format('H:i:s') : null,
                    'day_tour_end_time' => $isDayUse ? $checkOut->format('H:i:s') : null,
                    'promo_code_id' => $promoModel?->id,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                    'tax_rate' => $taxBreakdown['tax_rate'],
                ]);

                foreach ($rooms as $room) {
                    $nightlyRate = $this->roomPricingService->resolveNightlyRateForRoom($room);
                    $snapshot = $roomLineSnapshots[$room->id] ?? [
                        'rate' => $isDayUse
                            ? $this->roomPricingService->resolveDayUseRate((float) ($room->price_day_tour ?? 0), $nightlyRate)
                            : $nightlyRate,
                        'subtotal' => 0.0,
                    ];

                    BookingRoom::create([
                        'booking_id' => $booking->id,
                        'room_id' => $room->id,
                        'price_per_night' => $snapshot['rate'],
                        'nights' => $nights,
                        'subtotal' => $snapshot['subtotal'] > 0
                            ? $snapshot['subtotal']
                            : round($snapshot['rate'] * $nights, 2),
                    ]);
                }

                BookingGuest::create([
                    'booking_id' => $booking->id,
                    'name' => $request->guest_name,
                    'email' => $request->guest_email,
                    'phone' => $request->guest_phone,
                    'is_primary' => true,
                ]);

                if ($promoModel) {
                    $this->promoCodeService->recordUsage(
                        $promoModel,
                        $booking,
                        $discountAmount,
                        $request->guest_email
                    );
                }

                $payment = Payment::create([
                    'booking_id' => $booking->id,
                    'amount' => $totalAmount,
                    'amount_tendered' => $amountTendered,
                    'change_due' => $changeDue,
                    'payment_method' => $canonicalPaymentMethod,
                    'payment_type' => 'full_payment',
                    'payment_status' => 'completed',
                    'provider' => $canonicalPaymentMethod === 'gcash' ? 'manual_gcash' : 'manual',
                    'transaction_reference' => $paymentReference,
                    'paid_amount' => $totalAmount,
                    'paid_at' => $gcashPaidAt,
                    'verified_by' => auth()->id(),
                    'verified_at' => now(),
                    'notes' => ($isDayUse ? 'Day Use - ' : 'Walk-In - ') . 'payment collected at front desk.',
                    'staff_recording_key' => $request->idempotency_key,
                ]);

                $this->roomAssignmentService->assignRoomToBooking(
                    booking: $booking,
                    actorLabel: auth()->user()?->name ?? 'Receptionist',
                    allowHistoricalFallback: false
                );

                // Keep room-state DB updates inside the same transaction.
                $this->roomStateService->recalculateMany($roomIds);

                if ($canonicalPaymentMethod === 'gcash') {
                    $gcashSubmission = $this->frontDeskGcashPayments->recordApprovedSubmission(
                        payment: $payment,
                        booking: $booking,
                        staff: auth()->user(),
                        reference: (string) $paymentReference,
                        senderName: (string) $request->gcash_sender_name,
                        paidAt: $gcashPaidAt
                    );
                }
            });

            // Non-fatal post-booking step: audit log
            try {
                AuditHelper::log(
                    actionActivity: $isDayUse ? 'Day Use Booking Created' : 'Walk-In Booking Created',
                    modulePage: 'Walk-In Module',
                    modelType: 'Booking',
                    modelId: $booking->id,
                    recordAffected: 'Booking ' . $booking->reference_number . ' - ' . $request->guest_name,
                    newValues: [
                        'stay_type' => $stayType,
                        'check_in' => $booking->check_in->toDateTimeString(),
                        'check_out' => $booking->check_out->toDateTimeString(),
                        'duration_hours' => $durationHours,
                        'guest' => $request->guest_name,
                        'payment_method' => $canonicalPaymentMethod,
                        'amount_charged' => (float) $booking->total_amount,
                        'amount_tendered' => $amountTendered,
                        'change_due' => $changeDue,
                        'payment_reference' => $paymentReference,
                        'total_amount' => (float) $booking->total_amount,
                        'discount_amount' => (float) $booking->discount_amount,
                        'promo_code' => $request->promo_code ?? null,
                        'recorded_by' => [
                            'id' => auth()->id(),
                            'name' => auth()->user()->name ?? 'Receptionist',
                        ],
                    ],
                    action: 'created'
                );
            } catch (\Throwable $e) {
                Log::error('Walk-in audit log failed: ' . $e->getMessage(), [
                    'booking_reference' => $booking?->reference_number,
                ]);
            }

            // Non-fatal enrichment for response payload
            try {
                $booking->load(['bookingRooms.room', 'primaryGuest', 'payments']);
            } catch (\Throwable $e) {
                Log::error('Walk-in booking relation load failed: ' . $e->getMessage(), [
                    'booking_reference' => $booking?->reference_number,
                ]);
            }

            return $this->walkInResponse($booking, $payment);
        } catch (ValidationException $e) {
            $this->frontDeskGcashPayments->deleteEvidence($gcashSubmission);
            $errors = $e->errors();
            $message = collect($errors)->flatten()->first() ?? 'Payment validation failed.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors,
            ], 422);
        } catch (\Throwable $e) {
            $this->frontDeskGcashPayments->deleteEvidence($gcashSubmission);
            if ($e instanceof \RuntimeException && $e->getMessage() === 'ROOM_CONFLICT') {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected rooms are no longer available for the selected dates.',
                ], 422);
            }

            Log::error('Walk-in booking failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Walk-in booking could not be completed. Please try again.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function formatCurrency(float $amount): string
    {
        return 'PHP ' . number_format($amount, 2);
    }

    private function walkInCheckInError(Carbon $checkIn): ?string
    {
        $graceMinutes = max(0, (int) config('bookings.walk_in_check_in_grace_minutes', 15));
        $earliestCheckIn = now()->startOfMinute()->subMinutes($graceMinutes);

        if ($checkIn->gte($earliestCheckIn)) {
            return null;
        }

        return "Check-in cannot be more than {$graceMinutes} minutes in the past. Please use the current time or a future time.";
    }

    private function walkInResponse(Booking $booking, Payment $payment, bool $replayed = false)
    {
        $booking->loadMissing(['bookingRooms.room', 'primaryGuest', 'payments']);

        return response()->json([
            'success' => true,
            'message' => $replayed
                ? 'This walk-in booking and payment were already recorded.'
                : (($booking->stay_type === 'day_use' ? 'Day use' : 'Walk-in').' booking created successfully.'),
            'data' => [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->reference_number,
                'booking_source' => 'walk_in',
                'stay_type' => $booking->stay_type,
                'duration_hours' => (float) $booking->duration_hours,
                'total_amount' => (float) $booking->total_amount,
                'replayed' => $replayed,
                'payment' => [
                    'payment_method' => $payment->payment_method,
                    'amount_charged' => (float) $payment->amount,
                    'amount_tendered' => (float) ($payment->amount_tendered ?? $payment->amount),
                    'change_due' => (float) ($payment->change_due ?? 0),
                    'payment_reference' => $payment->transaction_reference,
                ],
                'booking' => $booking,
            ],
        ], 200);
    }
}
