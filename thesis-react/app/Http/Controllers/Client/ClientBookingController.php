<?php

namespace App\Http\Controllers\Client;

use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\BookingGuest;
use App\Models\Feedback;
use App\Models\Rebooking;
use App\Models\Room;
use App\Helpers\AuditHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use App\Services\AddOnCatalogService;
use App\Services\BookingCancellationService;
use App\Services\CancellationApprovalService;
use App\Services\DownpaymentService;
use App\Services\GuestPaymentAuthorizationService;
use App\Services\GuestBookingAccessSessionService;
use App\Services\PromoCodeService;
use App\Services\PaymentAccessSessionService;
use App\Services\PaymentProviderService;
use App\Services\RebookingRequestService;
use App\Services\RoomAssignmentService;
use App\Services\RoomPricingService;
use App\Services\TaxService;
use App\Support\PhilippineMobileNumber;

class ClientBookingController extends Controller
{
    public function __construct(
        private BookingCancellationService $bookingCancellationService,
        private CancellationApprovalService $cancellationApprovalService,
        private PaymentAccessSessionService $paymentAccessSessions,
        private PromoCodeService $promoCodeService,
        private RebookingRequestService $rebookingRequestService,
        private RoomAssignmentService $roomAssignmentService,
        private RoomPricingService $roomPricingService,
        private AddOnCatalogService $addOnCatalogService,
        private TaxService $taxService,
        private DownpaymentService $downpaymentService,
        private PaymentProviderService $paymentProviderService,
        private GuestPaymentAuthorizationService $guestPaymentAuthorization,
        private GuestBookingAccessSessionService $guestBookingAccessSessions,
    ) {
    }

    // GET /api/client/rooms/available
    public function getAvailableRooms(Request $request)
    {
        try {
            $checkInDate  = Carbon::parse($request->check_in)->format('Y-m-d');
            $checkOutDate = Carbon::parse($request->check_out)->format('Y-m-d');
            $todayDate    = Carbon::today()->format('Y-m-d');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'errors'  => ['check_in' => ['Please provide a valid date']],
            ], 422);
        }

        $validator = Validator::make([
            'check_in'         => $checkInDate,
            'check_out'        => $checkOutDate,
            'number_of_guests' => $request->number_of_guests,
        ], [
            'check_in' => [
                'required', 'date_format:Y-m-d',
                function ($attribute, $value, $fail) use ($todayDate) {
                    if ($value <= $todayDate) {
                        $fail(
                            'Online booking requires check-in from tomorrow onwards. ' .
                            'For same-day or day tour bookings, please visit the front desk.'
                        );
                    }
                },
            ],
            'check_out' => [
                'required', 'date_format:Y-m-d',
                function ($attribute, $value, $fail) use ($checkInDate) {
                    if ($value <= $checkInDate) $fail('Check-out must be after check-in.');
                },
            ],
            'number_of_guests' => 'required|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $numberOfGuests = (int) $request->number_of_guests;

            $pendingExpiryCutoff = now()->subMinutes((int) config('bookings.pending_expiry_minutes', 15));
            $now = now();

            $bookedRoomIds = DB::table('booking_rooms')
                ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
                ->where(function ($activeQ) {
                    $activeQ->whereNull('booking_rooms.room_status')
                        ->orWhere('booking_rooms.room_status', 'active');
                })
                ->where(function ($statusQ) use ($pendingExpiryCutoff, $now) {
                    $statusQ->whereIn('bookings.booking_status', ['confirmed', 'checked_in'])
                        ->orWhere(function ($pendingQ) use ($pendingExpiryCutoff, $now) {
                            $pendingQ->where('bookings.booking_status', 'pending')
                                ->where(function ($expiryQ) use ($pendingExpiryCutoff, $now) {
                                    $expiryQ->where(function ($hasExpiryQ) use ($now) {
                                        $hasExpiryQ->whereNotNull('bookings.expires_at')
                                            ->where('bookings.expires_at', '>', $now);
                                    })->orWhere(function ($legacyQ) use ($pendingExpiryCutoff) {
                                        $legacyQ->whereNull('bookings.expires_at')
                                            ->where('bookings.created_at', '>=', $pendingExpiryCutoff);
                                    });
                                });
                        });
                })
                ->where('bookings.check_in', '<', $checkOutDate)
                ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$checkInDate])
                ->whereNotNull('booking_rooms.room_id')
                ->pluck('booking_rooms.room_id')
                ->toArray();

            $heldRoomIds = DB::table('rebooking_room_holds')
                ->whereNull('released_at')
                ->where(function ($query) use ($now) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                })
                ->where('check_in', '<', $checkOutDate)
                ->where('check_out', '>', $checkInDate)
                ->pluck('room_id')
                ->toArray();
            $bookedRoomIds = array_values(array_unique(array_merge($bookedRoomIds, $heldRoomIds)));

            $roomsQuery = Room::query()
                ->whereNotIn('id', $bookedRoomIds)
                ->where('show_on_website', true)
                ->where('capacity', '>=', $numberOfGuests);

            if ($checkInDate === $todayDate) {
                $roomsQuery->where('status', 'available');
            } else {
                $roomsQuery->whereNotIn('status', ['maintenance', 'cleaning']);
            }

            $rooms = $roomsQuery
                ->orderBy('capacity', 'asc')
                ->get();

            $availableCountsByType = $rooms
                ->pluck('room_type')
                ->unique()
                ->mapWithKeys(function ($roomType) use ($checkInDate, $checkOutDate) {
                    return [
                        (string) $roomType => $this->roomAssignmentService->countAvailableRoomsByType(
                            roomType: (string) $roomType,
                            checkIn: $checkInDate,
                            checkOut: $checkOutDate,
                            websiteOnly: true
                        ),
                    ];
                });

            $rooms = $rooms
                ->groupBy('room_type')
                ->flatMap(function ($typeRooms, $roomType) use ($availableCountsByType) {
                    $availableCount = (int) ($availableCountsByType[$roomType] ?? 0);
                    $eligibleAvailableCount = min($availableCount, $typeRooms->count());

                    return $typeRooms
                        ->take($eligibleAvailableCount)
                        ->each(fn ($room) => $room->availability_count = $eligibleAvailableCount);
                })
                ->values();

            $rooms->transform(function ($room) {
                $room->price_per_night = $this->roomPricingService->resolveNightlyRateForRoom($room);

                $images = $room->images;
                if (is_string($images)) {
                    $images = json_decode($images, true) ?? [];
                }
                if (!is_array($images)) {
                    $images = [];
                }

                $images = array_filter($images, fn($p) => !empty($p));
                $room->image_urls = array_values(array_map(function ($p) {
                    if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
                        return $p;
                    }
                    $p = ltrim($p, '/');
                    if (str_starts_with($p, 'storage/')) {
                        return asset($p);
                    }
                    return asset('storage/' . $p);
                }, $images));

                return $room;
            });

            $rooms = $rooms
                ->sort(function ($a, $b) {
                    $capacityCompare = ((int) $a->capacity) <=> ((int) $b->capacity);
                    if ($capacityCompare !== 0) {
                        return $capacityCompare;
                    }

                    return ((float) $a->price_per_night) <=> ((float) $b->price_per_night);
                })
                ->values();

            return response()->json([
                'success' => true,
                'data'    => $rooms,
                'meta'    => [
                    'number_of_guests'  => $numberOfGuests,
                    'total_rooms_found' => $rooms->count(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch available rooms', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available rooms',
            ], 500);
        }
    }

    // GET /api/client/room-types/{slug}
    public function getRoomTypeDetails(string $slug)
    {
        $roomType = str_replace('-', '_', strtolower(trim($slug)));

        $allowedRoomTypes = [
            'executive_suite',
            'family',
            'deluxe',
            'superior_twin',
            'superior_queen',
            'premier',
        ];

        if (!in_array($roomType, $allowedRoomTypes, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Room type not found.',
            ], 404);
        }

        $room = Room::query()
            ->where('room_type', $roomType)
            ->where('show_on_website', true)
            ->orderByRaw("CASE WHEN status = 'available' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room type not found.',
            ], 404);
        }

        $pricePerNight = $this->roomPricingService->resolveNightlyRateForRoom($room);

        $images = $room->images;
        if (is_string($images)) {
            $images = json_decode($images, true) ?? [];
        }
        if (!is_array($images)) {
            $images = [];
        }

        $imageUrls = array_values(array_map(function ($p) {
            if (str_starts_with((string) $p, 'http://') || str_starts_with((string) $p, 'https://')) {
                return (string) $p;
            }
            $path = ltrim((string) $p, '/');
            if (str_starts_with($path, 'storage/')) {
                return asset($path);
            }
            return asset('storage/' . $path);
        }, array_filter($images, fn ($p) => !empty($p))));

        $amenities = $room->amenities;
        if (is_string($amenities)) {
            $amenities = json_decode($amenities, true) ?? [];
        }
        if (!is_array($amenities)) {
            $amenities = [];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'type_slug' => str_replace('_', '-', $roomType),
                'room_type' => $roomType,
                'name' => $this->formatRoomTypeLabel($roomType),
                'description' => (string) ($room->description ?? ''),
                'price_per_night' => (float) $pricePerNight,
                'images' => $imageUrls,
                'bed_type' => $this->deriveBedType($roomType),
                'capacity' => (int) ($room->capacity ?? 0),
                'size_sqm' => isset($room->size_sqm) ? (float) $room->size_sqm : null,
                'size' => isset($room->size_sqm) && $room->size_sqm !== null
                    ? ((float) $room->size_sqm . ' sqm')
                    : $this->deriveRoomSizeByType($roomType),
                'amenities' => array_values(array_map(fn ($v) => (string) $v, array_filter($amenities))),
            ],
        ]);
    }

    // POST /api/client/bookings
    public function store(Request $request)
    {
        Log::info('booking ip debug', [
            'ip' => $request->ip(),
            'xff' => $request->header('X-Forwarded-For'),
            'xri' => $request->header('X-Real-IP'),
        ]);

        try {
            $checkInDate  = Carbon::parse($request->check_in)->format('Y-m-d');
            $checkOutDate = Carbon::parse($request->check_out)->format('Y-m-d');
            $todayDate    = Carbon::today()->format('Y-m-d');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format',
                'errors'  => ['check_in' => ['Please provide a valid date']],
            ], 422);
        }

        $validationData = $request->only([
            'guest_name',
            'guest_email',
            'guest_phone',
            'guest_country',
            'guest_address_line_1',
            'guest_address_line_2',
            'guest_city',
            'guest_postal_code',
            'room_ids',
            'room_types',
            'adults_count',
            'children_count',
            'children_ages',
            'special_requests',
            'payment_method',
            'room_addons',
            'addons_total',
            'promo_code',
        ]);
        // Normalize before validation so blank names fail and replay hashes use
        // the same name that is persisted. Preserve non-string input for validation.
        if (is_string($validationData['guest_name'] ?? null)) {
            $validationData['guest_name'] = trim(
                preg_replace('/[\s\p{Z}]+/u', ' ', $validationData['guest_name'])
                    ?? $validationData['guest_name']
            );
        }
        $validationData = array_merge($validationData, [
            'guest_phone'      => PhilippineMobileNumber::normalize($validationData['guest_phone'] ?? null),
            'check_in'         => $checkInDate,
            'check_out'        => $checkOutDate,
            'number_of_guests' => $request->number_of_guests ?? (
                (int) $request->input('adults_count', 1) + (int) $request->input('children_count', 0)
            ),
        ]);

        $validator = Validator::make($validationData, [
            'guest_name'       => 'required|string|max:255',
            'guest_email'      => 'required|email:rfc,dns|max:255',
            'guest_phone'      => ['required', 'string', 'regex:/^\+639[0-9]{9}$/D'],
            'guest_country'    => 'required|string|size:2',
            'guest_address_line_1' => 'required|string|min:5|max:255',
            'guest_address_line_2' => 'nullable|string|max:255',
            'guest_city'       => 'required|string|min:2|max:120',
            'guest_postal_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9\s-]{2,19}$/'],
            'room_ids'         => 'required|array|min:1',
            'room_ids.*'       => 'exists:rooms,id',
            'room_types'       => 'sometimes|array|min:1',
            'room_types.*'     => 'sometimes|string|max:50',
            'check_in' => [
                'required', 'date_format:Y-m-d',
                function ($attribute, $value, $fail) use ($todayDate) {
                    if ($value <= $todayDate) {
                        $fail(
                            'Online booking requires check-in from tomorrow onwards. ' .
                            'For same-day or day tour bookings, please visit the front desk.'
                        );
                    }
                },
            ],
            'check_out' => [
                'required', 'date_format:Y-m-d',
                function ($attribute, $value, $fail) use ($checkInDate) {
                    if ($value <= $checkInDate) $fail('Check-out must be after check-in.');
                },
            ],
            'number_of_guests' => 'required|integer|min:1|max:10',
            'adults_count'     => 'nullable|integer|min:1|max:10',
            'children_count'   => 'nullable|integer|min:0|max:10',
            'children_ages'    => 'nullable|array',
            'children_ages.*'  => 'integer|min:1|max:17',
            'special_requests' => 'nullable|string|max:1000',
            'payment_method'   => 'nullable|in:gcash',
            'room_addons'      => 'nullable|array',
            'room_addons.*'    => 'array',
            'addons_total'     => 'nullable|numeric|min:0|max:999999',
            'promo_code'       => 'nullable|string|max:50',
        ], [
            'payment_method.in' => 'Only GCash payments are accepted. PayPal and other methods are not supported.',
            'guest_phone.regex' => 'Enter a Philippine mobile number: +63 followed by 10 digits starting with 9.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $paymentProviderIssues = $this->paymentProviderService->operationalIssues();
        if ($paymentProviderIssues !== []) {
            Log::warning('Online booking creation blocked by payment provider configuration', [
                'provider' => $this->paymentProviderService->provider(),
                'issues' => $paymentProviderIssues,
            ]);

            return response()->json([
                'success' => false,
                'error_code' => 'PAYMENT_PROVIDER_UNAVAILABLE',
                'message' => 'Online booking is temporarily unavailable while the payment option is being prepared. Please try again later or contact the hotel.',
            ], 503);
        }

        $guestEmail = strtolower(trim((string) $request->guest_email));
        $guestPhone = $validator->validated()['guest_phone'];
        $request->merge([
            'guest_name' => $validator->validated()['guest_name'],
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
        ]);

        $adultsCount = max(1, (int) $request->input('adults_count', (int) $request->number_of_guests));
        $childrenCount = max(0, (int) $request->input('children_count', 0));
        $childrenAgesInput = $request->input('children_ages', []);
        if (!is_array($childrenAgesInput)) {
            $childrenAgesInput = [];
        }

        $childrenAges = collect($childrenAgesInput)
            ->map(fn ($age) => (int) $age)
            ->filter(fn ($age) => $age >= 1 && $age <= 17)
            ->values()
            ->all();

        if ($childrenCount === 0 && count($childrenAges) > 0) {
            $childrenCount = count($childrenAges);
        }

        if ($childrenCount > 0) {
            if (count($childrenAges) === 0) {
                $childrenAges = array_fill(0, $childrenCount, 7);
                Log::warning('children_ages missing; defaulting up to 2 free children policy', [
                    'guest_email' => $guestEmail,
                    'children_count' => $childrenCount,
                ]);
            } elseif (count($childrenAges) < $childrenCount) {
                $childrenAges = array_merge($childrenAges, array_fill(0, $childrenCount - count($childrenAges), 7));
                Log::warning('children_ages shorter than children_count; padded with default age', [
                    'guest_email' => $guestEmail,
                    'children_count' => $childrenCount,
                    'children_ages_count' => count($childrenAgesInput),
                ]);
            } elseif (count($childrenAges) > $childrenCount) {
                $childrenAges = array_slice($childrenAges, 0, $childrenCount);
                Log::warning('children_ages longer than children_count; truncated', [
                    'guest_email' => $guestEmail,
                    'children_count' => $childrenCount,
                    'children_ages_count' => count($childrenAgesInput),
                ]);
            }
        } else {
            $childrenAges = [];
        }

        $totalGuests = $adultsCount + $childrenCount;
        if ($totalGuests > 10) {
            return response()->json([
                'success' => false,
                'message' => 'Total guests cannot exceed 10.',
            ], 422);
        }

        // Children policy is enforced for occupancy metadata only.
        // Pricing remains room-based (no per-person surcharge is applied).
        // If a surcharge is introduced later, update RoomPricingService + bookingPricing.js + summaries.
        $eligibleFreeChildrenByAge = collect($childrenAges)
            ->filter(fn ($age) => $age >= 1 && $age <= 7)
            ->count();
        $freeChildrenCount = min(2, $eligibleFreeChildrenByAge);
        $chargedChildrenCount = max(0, $childrenCount - $freeChildrenCount);
        $occupancyEquivalentAdultsCount = $adultsCount + $chargedChildrenCount;

        $request->merge([
            'adults_count' => $adultsCount,
            'children_count' => $childrenCount,
            'children_ages' => $childrenAges,
            'number_of_guests' => $totalGuests,
        ]);

        $captchaError = $this->validateBookingCaptcha($request);
        if ($captchaError !== null) {
            return response()->json([
                'success' => false,
                'message' => $captchaError,
            ], 422);
        }

        $idemKeyRaw = trim((string) $request->header('Idempotency-Key', ''));
        if ($idemKeyRaw === '') {
            $idemKeyRaw = trim((string) $request->input('idempotency_key', ''));
        }

        $idemCacheKey = null;
        $idemBodyHash = null;
        $idemLock = null;
        $idemTtlMinutes = max(1, (int) config('bookings.idempotency_ttl_minutes', 30));

        if ($idemKeyRaw !== '') {
            $idemPayload = $validator->validated();
            $idemPayload['guest_email'] = $guestEmail;
            $idemPayload['guest_phone'] = $guestPhone;
            $idemPayload['room_ids'] = array_values(array_map('intval', $idemPayload['room_ids'] ?? []));
            sort($idemPayload['room_ids']);
            $idemBodyHash = hash('sha256', json_encode($this->normalizeIdempotencyPayload($idemPayload), JSON_UNESCAPED_SLASHES));
            $idemCacheKey = 'booking_idem:' . hash('sha256', $request->ip() . '|' . $idemKeyRaw);

            $idemLock = Cache::lock($idemCacheKey . ':lock', 60);
            try {
                $idemLock->block(10);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking request is still being processed. Please retry shortly.',
                ], 409)->header('Retry-After', '2');
            }
        }

        try {
            if ($idemCacheKey && $idemBodyHash) {
                $existing = Cache::get($idemCacheKey);
                if (is_array($existing)) {
                    if (($existing['body_hash'] ?? '') !== $idemBodyHash) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Idempotency key reuse with different request data.',
                        ], 409);
                    }

                    $status = (int) ($existing['status'] ?? 409);
                    $payload = $existing['response'] ?? [
                        'success' => false,
                        'message' => 'Idempotency replay failed. Please retry.',
                    ];

                    return $this->attachImmediatePaymentHandoff(
                        $request,
                        response()->json($payload, $status),
                        (int) data_get($payload, 'data.booking.id', 0)
                    );
                }
            }

        $roomIdsForDedupe = array_values(array_map('intval', (array) $request->room_ids));
        sort($roomIdsForDedupe);
        $dedupeKey = 'booking_dedupe:' . hash('sha256', implode('|', [
            $guestEmail,
            $checkInDate,
            $checkOutDate,
            (string) ((int) $request->number_of_guests),
            implode(',', $roomIdsForDedupe),
        ]));

        $dedupeBookingId = Cache::get($dedupeKey);
        if (is_numeric($dedupeBookingId)) {
            $existingBooking = Booking::with(['bookingRooms.room', 'primaryGuest', 'payments'])
                ->whereKey((int) $dedupeBookingId)
                ->first();

            if ($existingBooking
                && $existingBooking->booking_status === 'pending'
                && $existingBooking->reservation_status !== 'cancelled'
                && !$existingBooking->isExpiredPending()
            ) {
                $totalAmount = (float) $existingBooking->total_amount;
                $downpaymentAmount = (float) $existingBooking->payments()
                    ->where('payment_type', 'downpayment')
                    ->latest()
                    ->value('amount');
                $downpaymentPercentage = $this->downpaymentService->resolvePercentage(
                    $existingBooking->downpayment_percentage !== null
                        ? (float) $existingBooking->downpayment_percentage
                        : null,
                    $totalAmount,
                    $downpaymentAmount
                );

                $responsePayload = [
                    'success' => true,
                    'message' => 'Booking already submitted. Continue to the secure payment page.',
                    'data'    => [
                        'booking' => [
                            'id'                 => $existingBooking->id,
                            'reference_number'   => $existingBooking->reference_number,
                            'booking_status'     => $existingBooking->booking_status,
                            'reservation_status' => $existingBooking->reservation_status,
                            'expires_at'         => optional($existingBooking->expires_at)->toIso8601String(),
                            'total_amount'       => $totalAmount,
                            'downpayment_amount' => $downpaymentAmount,
                            'remaining_balance'  => round(max(0, $totalAmount - $downpaymentAmount), 2),
                            'nights'             => $existingBooking->nights,
                        ],
                        'reference_number'       => $existingBooking->reference_number,
                        'nights'                 => $existingBooking->nights,
                        'downpayment_percentage' => $downpaymentPercentage,
                        'payment_window_minutes' => (int) config('bookings.pending_expiry_minutes', 30),
                        'next_url' => '/payment/' . $existingBooking->id,
                    ],
                ];

                return $this->attachImmediatePaymentHandoff(
                    $request,
                    response()->json($responsePayload, 200),
                    $existingBooking->id
                );
            }

            Cache::forget($dedupeKey);
        }

        $maxPerDay = max(1, (int) config('bookings.max_per_ip_per_day', 3));
        $emailBookingCount = BookingGuest::whereRaw('LOWER(email) = ?', [$guestEmail])
            ->whereHas('booking', function ($q) {
                $q->whereDate('created_at', today())
                  ->whereNotIn('booking_status', ['cancelled', 'rejected', 'no_show']);
            })
            ->count();

        if ($emailBookingCount >= $maxPerDay) {
            return response()->json([
                'success' => false,
                'message' => "Too many reservations submitted for this email today. Limit is {$maxPerDay} per day.",
            ], 429);
        }

        $committed           = false;
        $expiresAt = now()->addMinutes((int) config('bookings.pending_expiry_minutes', 30));

        DB::beginTransaction();

        try {
            $requestedRoomIds = array_values(array_map('intval', (array) $request->room_ids));
            $requestedRoomTemplates = Room::query()
                ->whereIn('id', $requestedRoomIds)
                ->get()
                ->keyBy('id');

            if ($requestedRoomTemplates->count() !== count(array_unique($requestedRoomIds))) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected room types are no longer available.',
                ], 400);
            }

            $lockedRoomsByType = $this->roomAssignmentService->lockRoomTypesForInventory(
                $requestedRoomTemplates->pluck('room_type')->all()
            );
            $lockedRoomsById = collect($lockedRoomsByType)
                ->flatten(1)
                ->keyBy('id');
            $rooms = collect($requestedRoomIds)
                ->map(fn ($roomId) => $lockedRoomsById->get($roomId))
                ->filter()
                ->values();

            if (
                $rooms->count() !== count($requestedRoomIds)
                || $rooms->contains(fn ($room) => ! (bool) $room->show_on_website)
            ) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected room types are no longer available.',
                ], 400);
            }

            $hasUndersizedRoom = $rooms->contains(
                fn ($room) => (int) $room->capacity < (int) $request->number_of_guests
            );
            if ($hasUndersizedRoom) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Each selected room must accommodate all {$request->number_of_guests} guests.",
                ], 400);
            }

            $requestedTypeCounts = $rooms
                ->map(fn ($room) => (string) $room->room_type)
                ->countBy();

            foreach ($requestedTypeCounts as $requestedType => $requestedCount) {
                $availableTypeCount = $this->roomAssignmentService->countAvailableRoomsByType(
                    roomType: (string) $requestedType,
                    checkIn: $checkInDate,
                    checkOut: $checkOutDate,
                    websiteOnly: true
                );

                if ($availableTypeCount < (int) $requestedCount) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Not enough available rooms for '
                            . $this->formatRoomTypeLabel((string) $requestedType)
                            . ' during the selected stay dates.',
                    ], 400);
                }
            }

            $checkIn  = Carbon::parse($checkInDate);
            $checkOut = Carbon::parse($checkOutDate);
            $nights   = $checkIn->diffInDays($checkOut);

            $alignedRoomAddOnsBySlot = $this->alignRoomAddonSelectionsToRoomIds(
                (array) $request->input('room_addons', []),
                $rooms->pluck('id')->all()
            );
            $normalizedAddOnsBySlot = $this->addOnCatalogService->normalizeSelections($alignedRoomAddOnsBySlot);
            $addonsTotal = $this->addOnCatalogService->totalForNormalizedSelections($normalizedAddOnsBySlot);

            $roomLineSnapshots = [];
            $roomSubtotal = 0.0;
            foreach ($rooms as $room) {
                $nightlyRate = $this->roomPricingService->resolveNightlyRateForRoom($room);
                $lineSubtotal = round($nightlyRate * $nights, 2);
                $roomLineSnapshots[$room->id] = [
                    'nightly_rate' => $nightlyRate,
                    'subtotal' => $lineSubtotal,
                ];
                $roomSubtotal += $lineSubtotal;
            }

            $preTaxSubtotal = round($roomSubtotal + $addonsTotal, 2);
            $discountedSubtotal = $preTaxSubtotal;

            // ── Promo code (optional) ─────────────────────────────────────────
            $promoModel     = null;
            $discountAmount = 0.0;

            if (!empty($request->promo_code)) {
                $promoResult = $this->promoCodeService->validateForReservation($request->promo_code, [
                    'guest_email'    => $guestEmail,
                    'check_in'       => $checkInDate,
                    'check_out'      => $checkOutDate,
                    'subtotal'       => $preTaxSubtotal,
                    'booking_source' => 'online',
                ]);

                if (!$promoResult['valid']) {
                    throw ValidationException::withMessages([
                        'promo_code' => $promoResult['message'],
                    ]);
                }

                $promoModel     = $promoResult['promo'];
                $discountAmount = $promoResult['discount_amount'];
                $discountedSubtotal = $promoResult['final_total'];
            }

            $taxBreakdown = $this->taxService->apply($discountedSubtotal);
            $taxAmount = $taxBreakdown['tax_amount'];
            $totalAmount = $taxBreakdown['total'];
            $downpaymentBreakdown = $this->downpaymentService->apply($totalAmount);
            $downpaymentPercentage = $downpaymentBreakdown['percentage'];
            $downpaymentAmount = $downpaymentBreakdown['amount'];
            $remainingBalance = $downpaymentBreakdown['remaining_balance'];

            $booking = Booking::create([
                'check_in'         => $checkInDate,
                'check_out'        => $checkOutDate,
                'number_of_guests' => $request->number_of_guests,
                'children_ages'    => $childrenAges,
                'booking_status'   => 'pending',
                'reservation_status' => 'pending_payment',
                'room_assignment_status' => 'pending_assignment',
                'room_assigned_at' => null,
                'expires_at'       => $expiresAt,
                'special_requests' => $request->special_requests,
                'total_amount'     => $totalAmount,
                'created_by'       => null,
                'booking_source'   => 'online',
                'is_day_tour'      => false,
                'promo_code_id'    => $promoModel?->id,
                'discount_amount'  => $discountAmount,
                'addons_amount'    => $addonsTotal,
                'tax_amount'       => $taxAmount,
                'tax_rate'         => $taxBreakdown['tax_rate'],
                'downpayment_percentage' => $downpaymentPercentage,
                'addons_breakdown' => [],
            ]);

            $createdBookingLinesBySlot = [];

            foreach ($rooms as $roomSlotIndex => $room) {
                $snapshot = $roomLineSnapshots[$room->id] ?? [
                    'nightly_rate' => (float) $room->price_per_night,
                    'subtotal' => round((float) $room->price_per_night * $nights, 2),
                ];

                $line = BookingRoom::create([
                    'booking_id'      => $booking->id,
                    'room_id'         => null,
                    'requested_room_type' => $room->room_type,
                    'price_per_night' => $snapshot['nightly_rate'],
                    'nights'          => $nights,
                    'subtotal'        => $snapshot['subtotal'],
                ]);

                $createdBookingLinesBySlot[(string) ((int) $roomSlotIndex)] = $line;
            }

            $normalizedBookingAddons = [];
            foreach ($createdBookingLinesBySlot as $slotKey => $line) {
                $sourceAddons = $normalizedAddOnsBySlot[$slotKey] ?? null;
                if (is_array($sourceAddons) && !empty($sourceAddons)) {
                    $normalizedBookingAddons[(string) ((int) $line->id)] = $sourceAddons;
                }
            }

            $booking->update([
                'addons_breakdown' => $normalizedBookingAddons,
            ]);

            BookingGuest::create([
                'booking_id'       => $booking->id,
                'name'             => $request->guest_name,
                'email'            => $guestEmail,
                'phone'            => $guestPhone,
                'country_code'     => strtoupper(trim((string) $request->guest_country)),
                'address_line_1'   => trim((string) $request->guest_address_line_1),
                'address_line_2'   => filled($request->guest_address_line_2) ? trim((string) $request->guest_address_line_2) : null,
                'city'             => trim((string) $request->guest_city),
                'postal_code'      => strtoupper(trim((string) $request->guest_postal_code)),
                'special_requests' => $request->guest_special_requests ?? null,
                'is_primary'       => true,
            ]);

            if ($promoModel) {
                $this->promoCodeService->recordUsage(
                    $promoModel,
                    $booking,
                    $discountAmount,
                    $guestEmail
                );
            }

            \App\Models\Payment::create([
                'booking_id'     => $booking->id,
                'amount'         => $downpaymentAmount,
                'payment_type'   => 'downpayment',
                'payment_method' => 'gcash',
                'payment_status' => 'pending',
                'provider'       => $this->paymentProviderService->provider(),
                'paid_at'        => null,
                'notes'          => $this->paymentProviderService->pendingPaymentNote($downpaymentPercentage),
            ]);

            DB::commit();
            $committed = true;

            $booking->load(['bookingRooms.room', 'primaryGuest', 'payments']);

            AuditHelper::log(
                actionActivity: 'Booking Created',
                modulePage: 'Booking Module',
                modelType: 'Booking',
                modelId: $booking->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: null,
                newValues: [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'guest_name' => $request->guest_name,
                    'adults_count' => $adultsCount,
                    'children_count' => $childrenCount,
                    'children_ages' => $childrenAges,
                    'free_children_count' => $freeChildrenCount,
                    'charged_children_count' => $chargedChildrenCount,
                    'occupancy_equivalent_adults_count' => $occupancyEquivalentAdultsCount,
                    'children_pricing_mode' => 'occupancy_only_no_surcharge',
                    'total_guests' => $totalGuests,
                ],
                action: 'created',
                actorLabel: 'Guest Portal'
            );

            Cache::put($dedupeKey, $booking->id, $expiresAt);

            $referenceNumber = $booking->reference_number;

            $responsePayload = [
                'success' => true,
                'message' => 'Booking submitted. Continue to the secure payment page.',
                'data'    => [
                    'booking' => [
                        'id'                 => $booking->id,
                        'reference_number'   => $referenceNumber,
                        'booking_status'     => $booking->booking_status,
                        'reservation_status' => $booking->reservation_status,
                        'expires_at'         => optional($booking->expires_at)->toIso8601String(),
                        'total_amount'       => $totalAmount,
                        'downpayment_amount' => $downpaymentAmount,
                        'remaining_balance'  => $remainingBalance,
                        'nights'             => $nights,
                    ],
                    'reference_number'       => $referenceNumber,
                    'nights'                 => $nights,
                    'downpayment_percentage' => $downpaymentPercentage,
                    'payment_window_minutes' => (int) config('bookings.pending_expiry_minutes', 30),
                    'next_url' => '/payment/' . $booking->id,
                ],
            ];

            if ($idemCacheKey && $idemBodyHash) {
                Cache::put($idemCacheKey, [
                    'body_hash' => $idemBodyHash,
                    'status'    => 201,
                    'response'  => $responsePayload,
                ], now()->addMinutes($idemTtlMinutes));
            }

            return $this->attachImmediatePaymentHandoff(
                $request,
                response()->json($responsePayload, 201),
                $booking->id
            );

        } catch (\Throwable $e) {
            if (!$committed && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($e instanceof ValidationException) {
                $errors = $e->errors();

                return response()->json([
                    'success' => false,
                    'message' => collect($errors)->flatten()->first() ?? 'Booking validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            Log::error('Booking creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create booking. Please try again.',
            ], 500);
        }
        } finally {
            $idemLock?->release();
        }
    }

    private function manualGcashHandoffEnabled(): bool
    {
        return $this->paymentProviderService->uses(PaymentProviderService::MANUAL_GCASH);
    }

    private function attachImmediatePaymentHandoff(
        Request $request,
        \Illuminate\Http\JsonResponse $response,
        int $bookingId
    ): \Illuminate\Http\JsonResponse {
        if (! $this->manualGcashHandoffEnabled() || $bookingId < 1) {
            return $response;
        }

        $booking = Booking::query()->whereKey($bookingId)->first();
        if (! $booking || $booking->booking_status !== 'pending' || $booking->isExpiredPending()) {
            return $response;
        }

        $bootstrap = $this->guestPaymentAuthorization->issueBootstrap($booking);
        $response->headers->setCookie($this->guestPaymentAuthorization->bootstrapCookie(
            $request,
            $booking->id,
            $bootstrap['token'],
            $bootstrap['expires_at']
        ));
        $response->headers->setCookie($this->guestBookingAccessSessions->issue($request, $booking));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    // POST /api/client/bookings/check-status
    public function checkBookingStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'            => 'required|email:rfc,dns|max:255',
            'reference_number' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $booking = Booking::with([
                'bookingRooms.room',
                'primaryGuest',
                'payments',
                'cancellation',
                'cancellationApprovalRequests',
                'rebookingsAsOriginal.requestedRoom',
                'rebookingsAsOriginal.originalRoom',
            ])
                ->where('reference_number', $request->reference_number)
                ->whereHas('primaryGuest', fn ($q) =>
                    $q->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $request->email))])
                )
                ->first();

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found or email does not match.',
                ], 404);
            }

            $latestPayment = $booking->payments->sortByDesc('created_at')->first();
            $latestManualSubmission = $latestPayment?->provider === 'manual_gcash'
                ? $latestPayment->manualGcashSubmissions()->latest('id')->first()
                : null;
            $manualGcashMaxAttempts = max(1, (int) config('payment.manual_gcash.max_submission_attempts', 3));
            $manualGcashAttemptsRemaining = max(
                0,
                $manualGcashMaxAttempts - (int) ($latestPayment?->submission_attempts ?? 0)
            );
            $canResubmitManualGcash = $booking->booking_status === 'pending'
                && $latestPayment?->provider === 'manual_gcash'
                && $latestPayment?->payment_status === 'pending'
                && $latestPayment?->payment_due_at?->isFuture()
                && $latestManualSubmission?->status === 'rejected'
                && $manualGcashAttemptsRemaining > 0;
            $feedback = Feedback::query()->where('booking_id', $booking->id)->first();
            $openCancellationRequest = $booking->cancellationApprovalRequests
                ->whereIn('status', ['pending_approval', 'approved', 'refund_pending', 'refunded'])
                ->whereNull('finalized_at')
                ->sortByDesc('id')
                ->first();
            $hasCancellationRequest = $booking->cancellationApprovalRequests
                ->whereIn('status', ['pending_approval', 'approved', 'refund_pending', 'refunded', 'cancelled'])
                ->isNotEmpty();
            $cancellationAttempted = $booking->cancellation !== null || $hasCancellationRequest;
            $rebookingRecords = $booking->rebookingsAsOriginal ?? collect();
            $openRebookingRequest = $rebookingRecords
                ->whereIn('status', Rebooking::OPEN_STATUSES)
                ->whereNull('finalized_at')
                ->sortByDesc('id')
                ->first();
            $hasApprovedRebooking = $rebookingRecords
                ->where('status', Rebooking::STATUS_APPROVED)
                ->isNotEmpty();
            $rebookingAttempted = $openRebookingRequest !== null
                || (bool) $booking->has_been_rebooked
                || $hasApprovedRebooking;
            $rebookingPendingRoomIds = $rebookingRecords
                ->whereIn('status', Rebooking::OPEN_STATUSES)
                ->whereNull('finalized_at')
                ->pluck('original_booking_room_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $latestApprovedRebooking = $rebookingRecords
                ->where('status', 'approved')
                ->sortByDesc(function ($row) {
                    return optional($row->approved_at ?? $row->updated_at ?? $row->created_at)->getTimestamp() ?? 0;
                })
                ->first();
            $latestRebookingPayload = null;
            if ($latestApprovedRebooking) {
                $fromRoomLabel = $latestApprovedRebooking->originalRoom
                    ? ('Room ' . $latestApprovedRebooking->originalRoom->room_number . ' (' . $latestApprovedRebooking->originalRoom->room_type . ')')
                    : null;
                $toRoomLabel = $latestApprovedRebooking->requestedRoom
                    ? ('Room ' . $latestApprovedRebooking->requestedRoom->room_number . ' (' . $latestApprovedRebooking->requestedRoom->room_type . ')')
                    : null;

                $latestRebookingPayload = [
                    'status' => (string) $latestApprovedRebooking->status,
                    'approved_at' => optional($latestApprovedRebooking->approved_at)->toIso8601String(),
                    'from_room' => $fromRoomLabel,
                    'to_room' => $toRoomLabel,
                    'note' => $latestApprovedRebooking->decision_note,
                ];
            }
            $storedTaxRate = $booking->tax_rate;
            $effectiveTaxAmount = $storedTaxRate !== null
                ? round(max(0, (float) ($booking->tax_amount ?? 0)), 2)
                : $this->taxService->calculate((float) $booking->total_amount);
            $childrenAges = collect($booking->children_ages ?? [])
                ->map(fn ($age) => (int) $age)
                ->filter(fn ($age) => $age >= 1 && $age <= 17)
                ->values()
                ->all();
            $childrenCount = count($childrenAges);
            $adultsCount = max(0, (int) $booking->number_of_guests - $childrenCount);
            $eligibleFreeChildren = collect($childrenAges)
                ->filter(fn ($age) => $age >= 1 && $age <= 7)
                ->count();
            $freeChildrenCount = min(2, $eligibleFreeChildren);
            $chargedChildrenCount = max(0, $childrenCount - $freeChildrenCount);
            $nonRefundable = $this->bookingCancellationService->isNonRefundableNow($booking);
            $safePayload = [
                'id' => (int) $booking->id,
                'reference_number' => $booking->reference_number,
                'booking_status' => $booking->booking_status,
                'check_in' => optional($booking->check_in)->format('Y-m-d'),
                'check_out' => optional($booking->check_out)->format('Y-m-d'),
                'number_of_guests' => (int) $booking->number_of_guests,
                'adults_count' => $adultsCount,
                'children_count' => $childrenCount,
                'children' => $childrenCount,
                'children_ages' => $childrenAges,
                'free_children_count' => $freeChildrenCount,
                'charged_children_count' => $chargedChildrenCount,
                'total_amount' => (float) $booking->total_amount,
                'total_paid' => (float) $booking->total_paid,
                'remaining_balance' => (float) $booking->remaining_balance,
                'discount_amount' => (float) ($booking->discount_amount ?? 0),
                'addons_amount' => (float) ($booking->addons_amount ?? 0),
                'tax_amount' => $effectiveTaxAmount,
                'tax_rate' => $storedTaxRate !== null
                    ? (float) $storedTaxRate
                    : $this->taxService->rate(),
                'addons_breakdown' => $booking->addons_breakdown ?? [],
                'promo_code' => $booking->promoCode?->code ?? null,
                'expires_at' => optional($booking->expires_at)->toIso8601String(),
                'created_at' => optional($booking->created_at)->toIso8601String(),
                'cancellation_attempted' => $cancellationAttempted,
                'cancellation_request_status' => $openCancellationRequest?->status,
                'cancellation_refund_status' => $booking->cancellationApprovalRequests->sortByDesc('id')->first()?->status,
                'cancellation_refund_amount' => (float) ($booking->cancellationApprovalRequests->sortByDesc('id')->first()?->refund_amount ?? 0),
                'lifecycle_locked_for_cancellation' => $openCancellationRequest !== null,
                'rebooking_attempted' => $rebookingAttempted,
                'rebooking_request_status' => $openRebookingRequest?->status,
                'lifecycle_locked_for_rebooking' => $openRebookingRequest !== null,
                'has_been_rebooked' => (bool) ($booking->has_been_rebooked || $hasApprovedRebooking),
                'rebooking_pending_room_ids' => $rebookingPendingRoomIds,
                'latest_rebooking' => $latestRebookingPayload,
                'cancellation_non_refundable' => $nonRefundable,
                'cancellation_refund_recipient' => app(\App\Services\CancellationRefundRecipientService::class)->context($booking),
                'room_assignment_status' => (string) ($booking->room_assignment_status ?? 'pending_assignment'),
                'room_assigned_at' => optional($booking->room_assigned_at)->toIso8601String(),
                'primary_guest' => [
                    'name' => $booking->primaryGuest?->name,
                    'email' => $booking->primaryGuest?->email,
                    'phone' => $booking->primaryGuest?->phone,
                ],
                'bookingRooms' => $booking->bookingRooms->map(function ($bookingRoom) use ($booking) {
                    $roomImages = $bookingRoom->room?->images ?? [];
                    if (is_string($roomImages)) {
                        $roomImages = json_decode($roomImages, true) ?? [];
                    }
                    if (!is_array($roomImages)) {
                        $roomImages = [];
                    }

                    $roomAmenities = $bookingRoom->room?->amenities ?? [];
                    if (is_string($roomAmenities)) {
                        $roomAmenities = json_decode($roomAmenities, true) ?? [];
                    }
                    if (!is_array($roomAmenities)) {
                        $roomAmenities = [];
                    }

                    return [
                        'booking_room_id' => (int) $bookingRoom->id,
                        'room_id' => $bookingRoom->room_id ? (int) $bookingRoom->room_id : null,
                        'room' => [
                            'id' => $bookingRoom->room?->id ? (int) $bookingRoom->room->id : null,
                            'room_number' => ($bookingRoom->room_id && $booking->room_assignment_status === 'assigned')
                                ? $bookingRoom->room?->room_number
                                : null,
                            'room_type'   => $bookingRoom->room?->room_type ?? $bookingRoom->requested_room_type,
                            'floor'       => $bookingRoom->room?->floor,
                            'bed_type'    => $this->deriveBedType($bookingRoom->room?->room_type ?? $bookingRoom->requested_room_type),
                            'description' => $bookingRoom->room?->description,
                            'amenities'   => array_values($roomAmenities),
                            'image_urls'  => collect($roomImages)
                                ->filter()
                                ->map(fn ($p) => str_starts_with((string) $p, 'http')
                                    ? $p
                                    : asset('storage/' . ltrim((string) $p, '/'))
                                )
                                ->values()
                                ->all(),
                        ],
                        'price_per_night' => (float) $bookingRoom->price_per_night,
                        'nights'          => (int) $bookingRoom->nights,
                        'subtotal'        => (float) $bookingRoom->subtotal,
                        'extended_checkout' => optional($bookingRoom->extended_checkout)->toDateTimeString(),
                        'extension_charge' => (float) ($bookingRoom->extension_charge ?? 0),
                        'addons'          => $this->resolveRoomAddons($booking, $bookingRoom),
                    ];
                })->values()->all(),
                'payment_summary' => [
                    'status' => $latestPayment?->payment_status,
                    'lifecycle_status' => $latestPayment?->resolvedLifecycleStatus(),
                    'method' => $latestPayment?->payment_method,
                    'paid_at' => optional($latestPayment?->paid_at)->toIso8601String(),
                ],
                'payment_resubmission' => [
                    'available' => $canResubmitManualGcash,
                    'payment_due_at' => optional($latestPayment?->payment_due_at)->toIso8601String(),
                    'attempts_remaining' => $manualGcashAttemptsRemaining,
                    'reason' => $latestManualSubmission?->review_reason,
                ],
                'payments' => $latestPayment ? [[
                    'provider' => $latestPayment->provider,
                    'payment_status' => $latestPayment->payment_status,
                    'lifecycle_status' => $latestPayment->resolvedLifecycleStatus(),
                    'payment_method' => $latestPayment->payment_method,
                    'paid_at' => optional($latestPayment->paid_at)->toIso8601String(),
                    'payment_due_at' => optional($latestPayment->payment_due_at)->toIso8601String(),
                    'amount' => (float) $latestPayment->amount,
                ]] : [],
                'feedback' => $feedback ? [
                    'is_submitted' => (bool) $feedback->is_submitted,
                    'submitted_at' => optional($feedback->submitted_at)->toIso8601String(),
                    'rating_overall' => $feedback->rating_overall,
                    'average_rating' => $feedback->average_rating,
                    'would_recommend' => $feedback->would_recommend,
                    'review' => $feedback->review,
                    'admin_reply' => $feedback->admin_reply,
                    'form_url' => !$feedback->is_submitted
                        ? rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/feedback/' . $feedback->token
                        : null,
                    'status_url' => URL::temporarySignedRoute(
                        'client.feedback.booking-status',
                        now()->addMinutes((int) config('bookings.feedback_link_ttl_minutes', 10080)),
                        ['reference' => $booking->reference_number]
                    ),
                ] : null,
            ];

            $response = response()->json(['success' => true, 'data' => $safePayload]);
            if (! $this->guestBookingAccessSessions->validate($request, $booking->id)) {
                $response->headers->setCookie($this->guestBookingAccessSessions->issue($request, $booking));
            }
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;

        } catch (\Exception $e) {
            Log::error('Failed to retrieve booking in checkBookingStatus', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve booking',
            ], 500);
        }
    }

    // POST /api/client/bookings/{id}/cancel
    public function cancel(Request $request, int $id)
    {
        if (! $this->guestBookingAccessSessions->validate($request, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Your secure booking session expired. Open My Bookings and search again.',
            ], 401);
        }

        $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        try {
            $payload = DB::transaction(function () use ($request, $id) {
                $booking = Booking::with([
                    'primaryGuest',
                    'payments',
                    'bookingRooms.room',
                    'cancellation',
                    'cancellationApprovalRequests',
                ])
                    ->lockForUpdate()
                    ->find($id);

                if (!$booking) {
                    return [404, [
                        'success' => false,
                        'message' => 'Booking not found.',
                    ]];
                }

                return $this->cancellationApprovalService->handleGuestCancellationForLockedBooking(
                    booking: $booking,
                    cancelledReasonCode: 'guest_cancelled',
                    reasonText: trim((string) $request->input('reason', '')),
                    refundRecipientDetails: $request->only(['refund_recipient_name', 'refund_recipient_account', 'refund_recipient_confirmed'])
                );
            });

            return response()->json($payload[1], $payload[0]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'BOOKING_NOT_CANCELLABLE:')) {
                $status = substr($e->getMessage(), strlen('BOOKING_NOT_CANCELLABLE:'));
                return response()->json([
                    'success' => false,
                    'message' => "This booking can no longer be cancelled (status: {$status}). Please contact the front desk.",
                ], 409);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Guest cancellation failed', [
                'booking_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to cancel booking right now. Please try again.',
            ], 500);
        }
    }

    // POST /api/client/bookings/{id}/rebook
    public function rebook(Request $request, int $id)
    {
        if (! $this->guestBookingAccessSessions->validate($request, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Your secure booking session expired. Open My Bookings and search again.',
            ], 401);
        }

        $request->validate([
            'room_changes' => 'required_without:new_room_id|array|min:1',
            'room_changes.*.booking_room_id' => 'required_with:room_changes|integer|exists:booking_rooms,id',
            'room_changes.*.requested_room_id' => 'required_with:room_changes|integer|exists:rooms,id',
            'new_room_id' => 'required_without:room_changes|integer|exists:rooms,id',
            // Preferred line-item selector (phase 1 contract).
            'booking_room_id' => 'sometimes|integer|exists:booking_rooms,id',
            // Backward-compatible alias for external callers.
            'original_booking_room_id' => 'sometimes|integer|exists:booking_rooms,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $payload = DB::transaction(function () use ($request, $id) {
                $booking = Booking::with(['primaryGuest', 'bookingRooms.room'])
                    ->lockForUpdate()
                    ->find($id);

                if (!$booking) {
                    return [404, ['success' => false, 'message' => 'Booking not found.']];
                }

                if ((string) $booking->booking_status !== 'confirmed') {
                    return [409, ['success' => false, 'message' => 'Room changes are available only after payment is verified and the booking is confirmed.']];
                }

                $createdAt = $booking->created_at ? Carbon::parse($booking->created_at) : null;
                $outside24HourWindow = !$createdAt || now()->greaterThan($createdAt->copy()->addHours(24));
                if ($outside24HourWindow) {
                    AuditHelper::log(
                        actionActivity: 'Rebooking Request Blocked',
                        modulePage: 'Rebooking Module',
                        modelType: 'Booking',
                        modelId: $booking->id,
                        recordAffected: 'Booking ' . $booking->reference_number,
                        oldValues: null,
                        newValues: [
                            'result' => 'blocked',
                            'reason' => 'outside_24hr_window',
                            'booking_id' => $booking->id,
                            'guest_name' => $booking->primaryGuest?->name ?? 'Guest',
                            'original_booking_created_at' => $createdAt?->toDateTimeString(),
                            'attempted_at' => now()->toDateTimeString(),
                        ],
                        action: 'blocked',
                        actorLabel: 'Guest Portal'
                    );

                    return [422, [
                        'success' => false,
                        'message' => 'Rebooking requests must be submitted within 24 hours of your original booking. This booking is no longer eligible for rebooking.',
                    ]];
                }

                $roomChangesInput = $request->input('room_changes');
                $normalizedRoomChanges = [];

                if (is_array($roomChangesInput) && count($roomChangesInput) > 0) {
                    foreach ($roomChangesInput as $change) {
                        if (!is_array($change)) {
                            continue;
                        }

                        $normalizedRoomChanges[] = [
                            'booking_room_id' => isset($change['booking_room_id']) ? (int) $change['booking_room_id'] : null,
                            'requested_room_id' => isset($change['requested_room_id']) ? (int) $change['requested_room_id'] : 0,
                        ];
                    }
                } else {
                    $selectedBookingRoomId = $request->input('booking_room_id', $request->input('original_booking_room_id'));
                    if (($selectedBookingRoomId === null || $selectedBookingRoomId === '') && $booking->bookingRooms->count() > 1) {
                        return [422, [
                            'success' => false,
                            'message' => 'Please specify which room you would like to change.',
                        ]];
                    }

                    $normalizedRoomChanges[] = [
                        'booking_room_id' => $selectedBookingRoomId !== null && $selectedBookingRoomId !== ''
                            ? (int) $selectedBookingRoomId
                            : null,
                        'requested_room_id' => (int) $request->input('new_room_id'),
                    ];
                }

                try {
                    $rebookings = $this->rebookingRequestService->createGuestRequestBatchForLockedBooking(
                        booking: $booking,
                        payload: [
                            'room_changes' => $normalizedRoomChanges,
                            'reason' => $request->input('reason'),
                        ],
                        actorLabel: 'Guest Portal'
                    );
                } catch (\RuntimeException $e) {
                    $code = $e->getMessage();
                    $response = match (true) {
                        $code === 'OPEN_REQUEST_EXISTS' => [409, ['success' => false, 'message' => 'An open room change request already exists for this booking. Please wait for staff review first.']],
                        $code === 'BOOKING_ALREADY_REBOOKED' => [409, ['success' => false, 'message' => 'This booking has already used its one allowed room change.']],
                        $code === 'BOOKING_NOT_CONFIRMED' => [409, ['success' => false, 'message' => 'Room changes are available only after payment is verified and the booking is confirmed.']],
                        $code === 'BOOKING_PAYMENT_NOT_SETTLED' => [409, ['success' => false, 'message' => 'Finish the pending payment or proof review before requesting a room change.']],
                        str_starts_with($code, 'BOOKING_LIFECYCLE_LOCKED:') => [409, ['success' => false, 'message' => 'Room changes are blocked while a cancellation request is in progress for this booking.']],
                        $code === 'ROOM_CHANGES_REQUIRED' => [422, ['success' => false, 'message' => 'Please select at least one room to change.']],
                        $code === 'BOOKING_ROOM_LINE_REQUIRED' => [422, ['success' => false, 'message' => 'Please specify which room you would like to change.']],
                        $code === 'BOOKING_ROOM_LINE_INVALID' => [422, ['success' => false, 'message' => 'Selected booking room line is invalid for this booking.']],
                        $code === 'BOOKING_ROOM_LINE_DUPLICATE' => [422, ['success' => false, 'message' => 'Each selected room can only be changed once per request.']],
                        $code === 'BOOKING_ROOM_LINE_MISSING' => [409, ['success' => false, 'message' => 'The selected booking room allocation is no longer available.']],
                        $code === 'NEW_ROOM_REQUIRED' => [422, ['success' => false, 'message' => 'Please select a new room.']],
                        $code === 'NEW_ROOM_SAME_AS_CURRENT' => [422, ['success' => false, 'message' => 'Please choose a different room from your current booking.']],
                        $code === 'REQUESTED_ROOM_DUPLICATE_IN_REQUEST' => [422, ['success' => false, 'message' => 'Please choose a different requested room for each selected booking room.']],
                        $code === 'REQUESTED_ROOM_NOT_FOUND' => [404, ['success' => false, 'message' => 'Requested room not found.']],
                        $code === 'REQUESTED_ROOM_NOT_OPERATIONAL' => [409, ['success' => false, 'message' => 'Selected room is under maintenance, cleaning, or is not ready for this stay.']],
                        $code === 'REQUESTED_ROOM_NOT_AVAILABLE' => [409, ['success' => false, 'message' => 'Selected room is no longer available for your stay dates.']],
                        default => null,
                    };

                    if ($response !== null) {
                        return $response;
                    }

                    throw $e;
                }

                $primaryRebooking = $rebookings[0] ?? null;
                $roomSummary = collect($rebookings)
                    ->map(function ($row) {
                        $fromLabel = $row->originalRoom
                            ? ("Room {$row->originalRoom->room_number} ({$row->originalRoom->room_type})")
                            : 'Original Room';
                        $toLabel = $row->requestedRoom
                            ? ("Room {$row->requestedRoom->room_number} ({$row->requestedRoom->room_type})")
                            : 'Requested Room';
                        return "{$fromLabel} -> {$toLabel}";
                    })
                    ->implode('; ');

                try {
                    \App\Helpers\NotificationHelper::rebookingRequested([
                        'booking_id' => $booking->reference_number,
                        'guest_name' => $booking->primaryGuest?->name ?? 'Guest',
                        'room' => $roomSummary,
                        'new_check_in' => optional($booking->check_in)->format('Y-m-d'),
                        'new_check_out' => optional($booking->check_out)->format('Y-m-d'),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to notify rebooking request', ['booking_id' => $booking->id]);
                }

                return [200, [
                    'success' => true,
                    'message' => count($rebookings) > 1
                        ? 'Room change request submitted for selected rooms.'
                        : 'Room change request submitted.',
                    'rebooking_id' => $primaryRebooking?->id,
                    'rebooking_ids' => collect($rebookings)->map(fn ($row) => (int) $row->id)->values()->all(),
                    'rebooking_group_id' => $primaryRebooking
                        ? (int) ($primaryRebooking->rebooking_group_id ?: $primaryRebooking->id)
                        : null,
                    'original_booking_room_id' => $primaryRebooking ? (int) $primaryRebooking->original_booking_room_id : null,
                    'original_booking_room_ids' => collect($rebookings)
                        ->map(fn ($row) => (int) $row->original_booking_room_id)
                        ->values()
                        ->all(),
                ]];
            }, 3);

            return response()->json($payload[1], $payload[0]);
        } catch (\Throwable $e) {
            Log::error('Guest rebooking failed', ['booking_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to submit room change request right now. Please try again.',
            ], 500);
        }
    }

    private function alignRoomAddonSelectionsToRoomIds(array $roomAddons, array $roomIds): array
    {
        if (empty($roomAddons) || empty($roomIds)) {
            return [];
        }

        $slotRoomIds = collect($roomIds)
            ->map(fn ($id) => (string) ((int) $id))
            ->values()
            ->all();
        $slotCount = count($slotRoomIds);
        $alignedBySlot = [];
        $unmappedAddonGroups = [];

        foreach ($roomAddons as $rawRoomKey => $addons) {
            if (!is_array($addons)) {
                continue;
            }

            $normalizedAddons = collect($addons)
                ->filter(fn ($addon) => is_array($addon))
                ->values()
                ->all();

            if (empty($normalizedAddons)) {
                continue;
            }

            // Preferred mapping: explicit room_index from frontend payload.
            $roomIndex = $this->extractRoomIndexFromAddonsGroup($normalizedAddons);
            if ($roomIndex !== null && $roomIndex >= 0 && $roomIndex < $slotCount) {
                $slotKey = (string) $roomIndex;
                $alignedBySlot[$slotKey] = array_merge($alignedBySlot[$slotKey] ?? [], $normalizedAddons);
                continue;
            }

            // Legacy fallback: numeric source room id as top-level key.
            // We map to the first unfilled slot using that source room id.
            $slotIndex = null;
            if (ctype_digit((string) $rawRoomKey)) {
                $normalizedRoomKey = (string) ((int) $rawRoomKey);
                foreach ($slotRoomIds as $candidateIndex => $slotRoomId) {
                    if ($slotRoomId !== $normalizedRoomKey) {
                        continue;
                    }

                    $candidateSlotKey = (string) $candidateIndex;
                    if (array_key_exists($candidateSlotKey, $alignedBySlot)) {
                        continue;
                    }

                    $slotIndex = (int) $candidateIndex;
                    break;
                }
            }

            if ($slotIndex !== null) {
                $slotKey = (string) $slotIndex;
                $alignedBySlot[$slotKey] = array_merge($alignedBySlot[$slotKey] ?? [], $normalizedAddons);
                continue;
            }

            $unmappedAddonGroups[] = $normalizedAddons;
        }

        foreach (array_keys($slotRoomIds) as $slotIndex) {
            $slotKey = (string) $slotIndex;
            if (!array_key_exists($slotKey, $alignedBySlot) && !empty($unmappedAddonGroups)) {
                $alignedBySlot[$slotKey] = array_shift($unmappedAddonGroups);
            }
        }

        return $alignedBySlot;
    }

    private function extractRoomIndexFromAddonsGroup(array $addons): ?int
    {
        foreach ($addons as $addon) {
            if (!is_array($addon) || !array_key_exists('room_index', $addon)) {
                continue;
            }

            $validatedIndex = filter_var($addon['room_index'], FILTER_VALIDATE_INT);
            if ($validatedIndex === false || $validatedIndex < 0) {
                continue;
            }

            return (int) $validatedIndex;
        }

        return null;
    }

    private function resolveRoomAddons(Booking $booking, BookingRoom $bookingRoom): array
    {
        $breakdown = $booking->addons_breakdown;
        if (!is_array($breakdown)) {
            return [];
        }

        $roomKeys = array_values(array_unique(array_filter([
            (string) ((int) $bookingRoom->id),
            (string) ((int) $bookingRoom->room_id),
        ])));
        $addons = [];

        foreach ($roomKeys as $roomKey) {
            if (isset($breakdown[$roomKey]) && is_array($breakdown[$roomKey])) {
                $addons = $breakdown[$roomKey];
                break;
            }
        }

        if (!is_array($addons)) {
            return [];
        }

        return collect($addons)
            ->filter(fn ($addon) => is_array($addon))
            ->map(function (array $addon) {
                $quantity = max(1, (int) ($addon['quantity'] ?? 1));
                $price = round((float) ($addon['price'] ?? 0), 2);
                $lineTotal = round((float) ($addon['line_total'] ?? ($price * $quantity)), 2);

                return [
                    'id' => (string) ($addon['id'] ?? $addon['addon_id'] ?? ''),
                    'name' => (string) ($addon['name'] ?? 'Add-on'),
                    'description' => $addon['description'] ?? null,
                    'quantity' => $quantity,
                    'price' => $price,
                    'line_total' => $lineTotal,
                ];
            })
            ->values()
            ->all();
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

    private function deriveRoomSizeByType(?string $roomType): ?string
    {
        return match ((string) $roomType) {
            'executive_suite' => '55 sqm',
            'family' => '45 sqm',
            'deluxe' => '28 sqm',
            'superior_twin', 'superior_queen' => '32 sqm',
            'premier' => '38 sqm',
            default => null,
        };
    }

    private function formatRoomTypeLabel(?string $roomType): string
    {
        return match ((string) $roomType) {
            'executive_suite' => 'Executive Suite',
            'superior_twin' => 'Superior Twin',
            'superior_queen' => 'Superior Queen',
            default => ucfirst(str_replace('_', ' ', (string) $roomType)),
        };
    }

    private function validatePaymentBootstrapToken(Booking $booking, string $token): bool
    {
        $providedHash = hash('sha256', $token);

        if (empty($booking->payment_bootstrap_token_hash)) {
            return false;
        }

        if ($booking->payment_bootstrap_expires_at && now()->greaterThan($booking->payment_bootstrap_expires_at)) {
            return false;
        }

        return hash_equals((string) $booking->payment_bootstrap_token_hash, $providedHash);
    }

    private function paymentAccessCookieName(int $bookingId): string
    {
        $prefix = (string) config('bookings.payment_access_cookie_prefix', 'hotel_payment_access_');

        return $prefix . $bookingId;
    }

    private function normalizeIdempotencyPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizeIdempotencyPayload($value);
                continue;
            }

            if (is_string($value)) {
                $payload[$key] = trim($value);
            }
        }

        return $payload;
    }

    private function validateBookingCaptcha(Request $request): ?string
    {
        if (!config('bookings.captcha.enabled', false)) {
            return null;
        }

        $provider = (string) config('bookings.captcha.provider', 'recaptcha');
        if ($provider !== 'recaptcha') {
            return 'CAPTCHA provider is not supported by server configuration.';
        }

        $secret = trim((string) config('bookings.captcha.secret', ''));
        if ($secret === '') {
            Log::error('Booking CAPTCHA is enabled but BOOKING_RECAPTCHA_SECRET is missing.');
            return 'Booking protection is temporarily unavailable. Please try again later.';
        }

        $token = trim((string) $request->input('captcha_token', ''));
        if ($token === '') {
            return 'CAPTCHA verification is required.';
        }

        try {
            $verify = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            if ($verify->failed()) {
                Log::warning('CAPTCHA verification request failed', [
                    'status' => $verify->status(),
                    'ip' => $request->ip(),
                ]);
                return 'CAPTCHA verification failed. Please retry.';
            }

            $data = $verify->json();
            if (!(bool) ($data['success'] ?? false)) {
                return 'CAPTCHA verification failed. Please retry.';
            }

            $expectedAction = (string) config('bookings.captcha.expected_action', 'booking_submit');
            $hasScore = array_key_exists('score', $data);
            $returnedAction = (string) ($data['action'] ?? '');

            if ($hasScore) {
                if ($returnedAction === '' || $returnedAction !== $expectedAction) {
                    return 'CAPTCHA verification action mismatch.';
                }
            } else {
                if ($returnedAction !== '' && $returnedAction !== $expectedAction) {
                    return 'CAPTCHA verification action mismatch.';
                }
            }

            $expectedHostnames = (array) config('bookings.captcha.expected_hostnames', []);
            if (!empty($expectedHostnames)) {
                $hostname = strtolower((string) ($data['hostname'] ?? ''));
                $allowed = collect($expectedHostnames)->map(fn ($h) => strtolower((string) $h))->all();
                if ($hostname === '' || !in_array($hostname, $allowed, true)) {
                    return 'CAPTCHA verification hostname mismatch.';
                }
            }

            if (array_key_exists('score', $data)) {
                $score = (float) $data['score'];
                $minScore = (float) config('bookings.captcha.min_score', 0.5);
                if ($score < $minScore) {
                    return 'Booking request blocked by anti-bot policy.';
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('CAPTCHA verification exception', [
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);
            return 'CAPTCHA verification failed. Please retry.';
        }
    }

    // GET /api/client/rooms/availability-calendar?month=3&year=2026
    public function getAvailabilityCalendar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2020|max:2100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $month = (int) $request->month;
        $year  = (int) $request->year;

        $firstDay = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $lastDay  = $firstDay->copy()->endOfMonth()->startOfDay();
        $today    = Carbon::today();

        $allRooms = Room::where('show_on_website', true)
            ->whereNotIn('status', ['maintenance', 'cleaning'])
            ->get();
        $totalRooms = $allRooms->count();

        if ($totalRooms === 0) {
            $calendar = [];
            $cursor = $firstDay->copy();
            while ($cursor->lte($lastDay)) {
                $calendar[$cursor->format('Y-m-d')] = 'fully_booked';
                $cursor->addDay();
            }
            return response()->json(['success' => true, 'data' => $calendar]);
        }

        $pendingExpiryCutoff = now()->subMinutes((int) config('bookings.pending_expiry_minutes', 15));
        $now = now();

        $activeBookings = BookingRoom::with('booking:id,check_in,check_out,booking_status,expires_at,created_at')
            ->active()
            ->whereHas('booking', function ($q) use ($firstDay, $lastDay, $pendingExpiryCutoff, $now) {
                $q->where(function ($statusQ) use ($pendingExpiryCutoff, $now) {
                    $statusQ->whereIn('booking_status', ['confirmed', 'checked_in'])
                        ->orWhere(function ($pendingQ) use ($pendingExpiryCutoff, $now) {
                            $pendingQ->where('booking_status', 'pending')
                                ->where(function ($expiryQ) use ($pendingExpiryCutoff, $now) {
                                    $expiryQ->where(function ($hasExpiryQ) use ($now) {
                                        $hasExpiryQ->whereNotNull('expires_at')
                                            ->where('expires_at', '>', $now);
                                    })->orWhere(function ($legacyQ) use ($pendingExpiryCutoff) {
                                        $legacyQ->whereNull('expires_at')
                                            ->where('created_at', '>=', $pendingExpiryCutoff);
                                    });
                                });
                        });
                })
                ->where('check_in',  '<', $lastDay->copy()->addDay()->format('Y-m-d'))
                ->where('check_out', '>', $firstDay->format('Y-m-d'));
            })
            ->get(['room_id', 'booking_id', 'requested_room_type', 'extended_checkout']);

        $roomBookings = [];
        $pendingTypeBookings = [];
        foreach ($activeBookings as $br) {
            if (!$br->booking) continue;
            $effectiveCheckout = $br->extended_checkout
                ? Carbon::parse($br->extended_checkout)->startOfDay()
                : Carbon::parse($br->booking->check_out)->startOfDay();
            $bookingWindow = [
                Carbon::parse($br->booking->check_in)->startOfDay(),
                $effectiveCheckout,
            ];

            if ($br->room_id !== null) {
                $roomBookings[$br->room_id][] = $bookingWindow;
            } elseif ($br->requested_room_type) {
                $pendingTypeBookings[(string) $br->requested_room_type][] = $bookingWindow;
            }
        }

        $offlineRoomIds = Room::where('show_on_website', true)
            ->whereIn('status', ['maintenance', 'cleaning'])
            ->pluck('id')
            ->flip()
            ->toArray();

        $calendar = [];
        $cursor = $firstDay->copy();

        while ($cursor->lte($lastDay)) {
            $dateStr = $cursor->format('Y-m-d');

            if ($cursor->lte($today)) {
                $calendar[$dateStr] = 'unavailable';
                $cursor->addDay();
                continue;
            }

            $availableByType = $allRooms
                ->groupBy('room_type')
                ->map(fn ($typeRooms) => $typeRooms->count())
                ->all();

            foreach ($allRooms as $room) {
                if (isset($offlineRoomIds[$room->id])) {
                    continue;
                }

                $isBooked = false;
                if (isset($roomBookings[$room->id])) {
                    foreach ($roomBookings[$room->id] as [$checkIn, $checkOut]) {
                        if ($cursor->gte($checkIn) && $cursor->lt($checkOut)) {
                            $isBooked = true;
                            break;
                        }
                    }
                }

                if ($isBooked) {
                    $availableByType[$room->room_type] = max(
                        0,
                        (int) ($availableByType[$room->room_type] ?? 0) - 1
                    );
                }
            }

            foreach ($pendingTypeBookings as $roomType => $bookingWindows) {
                foreach ($bookingWindows as [$checkIn, $checkOut]) {
                    if ($cursor->gte($checkIn) && $cursor->lt($checkOut)) {
                        $availableByType[$roomType] = max(
                            0,
                            (int) ($availableByType[$roomType] ?? 0) - 1
                        );
                    }
                }
            }

            $availableCount = array_sum($availableByType);

            $calendar[$dateStr] = $availableCount > 0 ? 'available' : 'fully_booked';
            $cursor->addDay();
        }

        return response()->json(['success' => true, 'data' => $calendar]);
    }
}
