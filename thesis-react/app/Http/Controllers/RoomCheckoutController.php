<?php

namespace App\Http\Controllers;

use App\Helpers\AuditHelper;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Services\CheckoutStayService;
use App\Services\TaxService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoomCheckoutController extends Controller
{
    public function __construct(
        private CheckoutStayService $checkoutStayService,
        private TaxService $taxService,
    ) {
    }

    public function checkout(Request $request, string $bookingId, int $roomId)
    {
        $request->validate([
            'extra_charges' => 'nullable|numeric|min:0',
            'booking_room_id' => 'nullable|integer|exists:booking_rooms,id',
        ]);

        $extraCharges = round((float) ($request->input('extra_charges', 0)), 2);

        if ($extraCharges > 0.009) {
            return response()->json([
                'success' => false,
                'message' => 'Save extra charges and collect the updated balance before checking out this room.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $bookingId, $roomId) {
                $booking = $this->loadBookingForUpdate($bookingId);
                if (!$booking) {
                    throw new \RuntimeException('BOOKING_NOT_FOUND');
                }

                [$booking, $bookingRoom] = $this->checkoutStayService->checkoutRoom(
                    booking: $booking,
                    roomId: $roomId,
                    bookingRoomId: $request->filled('booking_room_id')
                        ? (int) $request->input('booking_room_id')
                        : null,
                    actorId: (int) auth()->id(),
                );

                $booking->load([
                    'bookingRooms.room',
                    'primaryGuest',
                    'creator',
                    'payments',
                ]);

                return [$booking, $bookingRoom];
            });

            [$booking, $bookingRoom] = $result;

            AuditHelper::log(
                actionActivity: 'Room Checked Out',
                modulePage: 'Check-Out Module',
                modelType: 'BookingRoom',
                modelId: (int) $bookingRoom->id,
                recordAffected: 'Booking ' . $booking->reference_number . ' - Room ' . ($bookingRoom->room?->room_number ?? $roomId),
                oldValues: [
                    'room_status' => 'active',
                    'checked_out_at' => null,
                ],
                newValues: [
                    'room_status' => 'checked_out',
                    'checked_out_at' => optional($bookingRoom->checked_out_at)->toDateTimeString(),
                    'checkout_extra_charges' => (float) ($bookingRoom->checkout_extra_charges ?? 0),
                    'processed_by' => auth()->user()?->name ?? 'Receptionist',
                ],
                action: 'updated'
            );

            return response()->json([
                'success' => true,
                'message' => 'Room checked out successfully. Room set to cleaning.',
                'data' => $booking,
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'BOOKING_NOT_FOUND') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found.',
                ], 404);
            }

            if ($e->getMessage() === 'ROOM_NOT_IN_BOOKING') {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected room does not belong to this booking.',
                ], 404);
            }

            if ($e->getMessage() === 'ROOM_ALREADY_CHECKED_OUT') {
                return response()->json([
                    'success' => false,
                    'message' => 'This room has already been checked out.',
                ], 409);
            }

            if ($e->getMessage() === 'ROOM_LINE_MISMATCH') {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected booking room line does not match the target room.',
                ], 422);
            }

            if ($e->getMessage() === 'ROOM_NOT_FOUND') {
                return response()->json([
                    'success' => false,
                    'message' => 'The assigned room could not be found.',
                ], 404);
            }

            if (str_starts_with($e->getMessage(), 'STATUS_INVALID:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only checked-in bookings can perform per-room checkout.',
                ], 400);
            }

            if (str_starts_with($e->getMessage(), 'BALANCE_DUE:')) {
                $remainingBalance = (float) substr($e->getMessage(), strlen('BALANCE_DUE:'));
                return response()->json([
                    'success' => false,
                    'message' => 'Checkout blocked: remaining balance must be settled first.',
                    'remaining_balance' => round($remainingBalance, 2),
                ], 422);
            }

            if (str_starts_with($e->getMessage(), 'BOOKING_LIFECYCLE_LOCKED:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Room checkout is blocked while a cancellation request is in progress.',
                ], 409);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Room checkout failed', [
                'booking_id' => $bookingId,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check out room.',
            ], 500);
        }
    }

    public function extend(Request $request, string $bookingId, int $roomId)
    {
        $request->validate([
            'new_checkout_date' => 'required|date_format:Y-m-d',
            'reason' => 'required|string|min:3|max:1000',
            'booking_room_id' => 'nullable|integer|exists:booking_rooms,id',
        ]);

        try {
            $result = DB::transaction(function () use ($request, $bookingId, $roomId) {
                $booking = $this->loadBookingForUpdate($bookingId);
                if (!$booking) {
                    throw new \RuntimeException('BOOKING_NOT_FOUND');
                }

                $this->checkoutStayService->assertExtensionAllowed($booking);

                $requestedBookingRoomId = $request->input('booking_room_id');
                $bookingRoomQuery = BookingRoom::query()
                    ->where('booking_id', $booking->id);

                if ($requestedBookingRoomId !== null) {
                    $bookingRoomQuery->where('id', (int) $requestedBookingRoomId);
                } else {
                    $bookingRoomQuery->where('room_id', $roomId);
                }

                $bookingRoom = $bookingRoomQuery->lockForUpdate()->first();

                if (!$bookingRoom) {
                    throw new \RuntimeException('ROOM_NOT_IN_BOOKING');
                }

                if ($requestedBookingRoomId !== null && (int) ($bookingRoom->room_id ?? 0) !== $roomId) {
                    throw new \RuntimeException('ROOM_LINE_MISMATCH');
                }

                if (($bookingRoom->room_status ?? 'active') !== 'active') {
                    throw new \RuntimeException('ROOM_NOT_ACTIVE');
                }

                if (! Room::query()->whereKey($roomId)->lockForUpdate()->first()) {
                    throw new \RuntimeException('ROOM_NOT_FOUND');
                }

                $currentCheckout = $this->checkoutStayService->effectiveCheckoutAt($bookingRoom, $booking);
                $newCheckout = Carbon::createFromFormat('Y-m-d', (string) $request->new_checkout_date)
                    ->setTime(
                        $currentCheckout->hour,
                        $currentCheckout->minute,
                        $currentCheckout->second
                    );

                if (!$newCheckout->gt($currentCheckout)) {
                    throw new \RuntimeException('INVALID_EXTENSION_DATE');
                }

                $maximumStayEnd = Carbon::parse((string) $booking->check_in)
                    ->addDays((int) config('bookings.stay_extension_max_days', 30));
                if ($newCheckout->gt($maximumStayEnd)) {
                    throw new \RuntimeException('EXTENSION_TOO_LONG');
                }

                $hasConflict = $this->hasRoomOverlapForExtension(
                    roomId: $roomId,
                    booking: $booking,
                    bookingRoom: $bookingRoom,
                    extensionStart: $currentCheckout,
                    extensionEnd: $newCheckout
                );
                if ($hasConflict) {
                    throw new \RuntimeException('ROOM_NOT_AVAILABLE_FOR_EXTENSION');
                }

                $additionalNights = max(1, $currentCheckout->copy()->startOfDay()->diffInDays($newCheckout->copy()->startOfDay()));
                $nightlyRate = round((float) ($bookingRoom->price_per_night ?? 0), 2);
                $oldExtensionCharge = round((float) ($bookingRoom->extension_charge ?? 0), 2);
                $oldLineSubtotal = round((float) ($bookingRoom->subtotal ?? 0), 2);
                $lineCheckIn = Carbon::parse((string) $booking->check_in);
                $recalculatedNights = max(1, $lineCheckIn->copy()->startOfDay()->diffInDays($newCheckout->copy()->startOfDay()));
                $newLineSubtotal = round($nightlyRate * $recalculatedNights, 2);
                $chargeDelta = round($newLineSubtotal - $oldLineSubtotal, 2);
                if ($chargeDelta <= 0) {
                    throw new \RuntimeException('INVALID_EXTENSION_CHARGE');
                }
                $newExtensionCharge = round($oldExtensionCharge + $chargeDelta, 2);

                $bookingRoom->update([
                    'extended_checkout' => $newCheckout->toDateTimeString(),
                    'extension_charge' => $newExtensionCharge,
                    'nights' => $recalculatedNights,
                    'subtotal' => $newLineSubtotal,
                    'extension_approved_at' => now(),
                    'extension_approved_by' => auth()->id(),
                ]);

                $newTotal = round((float) $booking->total_amount + $chargeDelta, 2);
                $taxRate = $this->bookingTaxRate($booking);
                $booking->update([
                    'total_amount' => $newTotal,
                    'tax_amount' => $this->taxService->calculate($newTotal, $taxRate),
                    'tax_rate' => $taxRate,
                ]);

                $this->checkoutStayService->syncBookingCheckOutFromActiveLines($booking);

                $booking->load([
                    'bookingRooms.room',
                    'bookingRooms.extensionApprover',
                    'primaryGuest',
                    'creator',
                    'payments',
                ]);

                return [
                    $booking,
                    $bookingRoom,
                    $currentCheckout,
                    $newCheckout,
                    $oldExtensionCharge,
                    $chargeDelta,
                    $additionalNights,
                ];
            });

            [
                $booking,
                $bookingRoom,
                $oldCheckout,
                $newCheckout,
                $oldExtensionCharge,
                $chargeDelta,
                $additionalNights,
            ] = $result;

            AuditHelper::log(
                actionActivity: 'Room Stay Extended',
                modulePage: 'Check-Out Module',
                modelType: 'BookingRoom',
                modelId: (int) $bookingRoom->id,
                recordAffected: 'Booking ' . $booking->reference_number . ' - Room ' . ($bookingRoom->room?->room_number ?? $roomId),
                oldValues: [
                    'effective_checkout' => $oldCheckout->toDateTimeString(),
                    'extension_charge' => $oldExtensionCharge,
                ],
                newValues: [
                    'extended_checkout' => optional($bookingRoom->extended_checkout)->toDateTimeString(),
                    'extension_charge' => round((float) ($bookingRoom->extension_charge ?? 0), 2),
                    'additional_nights' => $additionalNights,
                    'booking_total_delta' => $chargeDelta,
                    'reason' => trim((string) $request->input('reason', '')) ?: null,
                    'approved_by' => auth()->user()?->name ?? 'Receptionist',
                ],
                action: 'updated'
            );

            return response()->json([
                'success' => true,
                'message' => 'Room stay extended successfully.',
                'data' => [
                    'booking' => $booking,
                    'room' => [
                        'booking_room_id' => (int) $bookingRoom->id,
                        'room_id' => (int) ($bookingRoom->room_id ?? 0),
                        'extended_checkout' => optional($newCheckout)->toDateTimeString(),
                        'extension_charge' => round((float) ($bookingRoom->extension_charge ?? 0), 2),
                        'additional_nights' => (int) $additionalNights,
                        'nights' => (int) ($bookingRoom->nights ?? 0),
                        'subtotal' => round((float) ($bookingRoom->subtotal ?? 0), 2),
                    ],
                ],
            ]);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            return match (true) {
                $code === 'BOOKING_NOT_FOUND' => response()->json([
                    'success' => false,
                    'message' => 'Booking not found.',
                ], 404),
                $code === 'ROOM_NOT_IN_BOOKING' => response()->json([
                    'success' => false,
                    'message' => 'Selected room does not belong to this booking.',
                ], 404),
                $code === 'ROOM_NOT_ACTIVE' => response()->json([
                    'success' => false,
                    'message' => 'Only active rooms can be extended.',
                ], 409),
                $code === 'ROOM_LINE_MISMATCH' => response()->json([
                    'success' => false,
                    'message' => 'Selected booking room line does not match the target room.',
                ], 422),
                $code === 'ROOM_NOT_FOUND' => response()->json([
                    'success' => false,
                    'message' => 'The assigned room could not be found.',
                ], 404),
                $code === 'INVALID_EXTENSION_DATE' => response()->json([
                    'success' => false,
                    'message' => 'New checkout date must be after the current checkout date.',
                ], 422),
                $code === 'ROOM_NOT_AVAILABLE_FOR_EXTENSION' => response()->json([
                    'success' => false,
                    'message' => 'Room is not available for the requested extension period.',
                ], 409),
                $code === 'EXTENSION_TOO_LONG' => response()->json([
                    'success' => false,
                    'message' => 'The requested extension exceeds the maximum allowed stay length.',
                ], 422),
                $code === 'INVALID_EXTENSION_CHARGE' => response()->json([
                    'success' => false,
                    'message' => 'The extension charge could not be calculated safely. Please review the booking rates.',
                ], 409),
                str_starts_with($code, 'BOOKING_LIFECYCLE_LOCKED:') => response()->json([
                    'success' => false,
                    'message' => 'Stay extension is blocked while a cancellation request is in progress.',
                ], 409),
                str_starts_with($code, 'STATUS_INVALID:') => response()->json([
                    'success' => false,
                    'message' => 'Only checked-in bookings can perform per-room extension.',
                ], 400),
                default => throw $e,
            };
        } catch (\Throwable $e) {
            Log::error('Room extension failed', [
                'booking_id' => $bookingId,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to extend room stay.',
            ], 500);
        }
    }

    private function bookingTaxRate(Booking $booking): float
    {
        return $booking->tax_rate !== null
            ? (float) $booking->tax_rate
            : $this->taxService->rate();
    }

    private function loadBookingForUpdate(string $bookingId): ?Booking
    {
        return Booking::query()
            ->where(function ($q) use ($bookingId) {
                $q->where('id', $bookingId)
                    ->orWhere('reference_number', $bookingId);
            })
            ->lockForUpdate()
            ->first();
    }

    private function hasRoomOverlapForExtension(
        int $roomId,
        Booking $booking,
        BookingRoom $bookingRoom,
        Carbon $extensionStart,
        Carbon $extensionEnd
    ): bool {
        $pendingExpiryCutoff = now()->subMinutes((int) config('bookings.pending_expiry_minutes', 30));
        $now = now();

        return DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->where('booking_rooms.room_id', $roomId)
            ->where('booking_rooms.id', '!=', $bookingRoom->id)
            ->where('bookings.id', '!=', $booking->id)
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
            ->where('bookings.check_in', '<', $extensionEnd->toDateTimeString())
            ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$extensionStart->toDateTimeString()])
            ->exists();
    }
}
