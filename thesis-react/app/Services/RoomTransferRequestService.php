<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Helpers\NotificationHelper;
use App\Enums\NotificationType;
use App\Mail\BookingWorkflowDecisionMail;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomTransferRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class RoomTransferRequestService
{
    public function __construct(
        private RoomStateService $roomStateService,
        private RoomPricingService $roomPricingService
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public function createRequest(string|int $bookingKey, array $payload, User $requester): RoomTransferRequest
    {
        return DB::transaction(function () use ($bookingKey, $payload, $requester) {
            $booking = $this->findBookingForUpdate($bookingKey);
            $requestedBookingRoomId = isset($payload['booking_room_id'])
                ? (int) $payload['booking_room_id']
                : null;
            $bookingRoom = $this->assertBookingTransferable($booking, $requestedBookingRoomId);
            $this->assertNoOpenRequest($booking->id, (int) $bookingRoom->id);

            $targetRoom = Room::query()
                ->lockForUpdate()
                ->findOrFail((int) $payload['target_room_id']);

            $this->assertTargetRoomIsValid($booking, $bookingRoom, $targetRoom);

            $request = RoomTransferRequest::create([
                'booking_id' => $booking->id,
                'booking_room_id' => $bookingRoom->id,
                'current_room_id' => $bookingRoom->room_id,
                'target_room_id' => $targetRoom->id,
                'reason' => trim((string) ($payload['reason'] ?? '')),
                'requested_by' => $requester->id,
                'status' => RoomTransferRequest::STATUS_PENDING_APPROVAL,
            ]);

            AuditHelper::log(
                actionActivity: 'Room Transfer Request Created',
                modulePage: 'Reservation Module',
                modelType: 'RoomTransferRequest',
                modelId: $request->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: null,
                newValues: [
                    'booking_id' => $booking->id,
                    'current_room_id' => (int) $request->current_room_id,
                    'target_room_id' => (int) $request->target_room_id,
                    'status' => $request->status,
                ],
                action: 'created',
                actorUser: $requester
            );

            $this->notifyActiveAdmins(
                NotificationType::ROOM_TRANSFER_REQUESTED,
                'Room Transfer Approval Needed',
                "{$requester->name} requested a room transfer for booking {$booking->reference_number} from Room {$bookingRoom->room?->room_number} to Room {$targetRoom->room_number}.",
                [
                    'request_id' => $request->id,
                    'booking_id' => $booking->id,
                    'room' => $targetRoom->room_number,
                    'requested_by' => $requester->name,
                    'reason' => $request->reason,
                ]
            );

            return $request->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'bookingRoom.room',
                'currentRoom',
                'targetRoom',
                'requester',
                'approver',
                'completer',
            ]);
        }, 3);
    }

    /**
     * @throws \RuntimeException
     */
    public function approve(int $requestId, User $admin, ?string $decisionNote = null): RoomTransferRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $decisionNote) {
            $request = RoomTransferRequest::query()
                ->with(['booking'])
                ->lockForUpdate()
                ->findOrFail($requestId);

            if ($request->status !== RoomTransferRequest::STATUS_PENDING_APPROVAL) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $booking = Booking::query()
                ->with('bookingRooms')
                ->lockForUpdate()
                ->findOrFail($request->booking_id);
            $bookingRoom = $this->assertBookingTransferable(
                $booking,
                $request->booking_room_id !== null ? (int) $request->booking_room_id : null
            );

            $targetRoom = Room::query()->lockForUpdate()->findOrFail((int) $request->target_room_id);
            $this->assertTargetRoomIsValid($booking, $bookingRoom, $targetRoom);

            $request->status = RoomTransferRequest::STATUS_APPROVED;
            $request->approved_by = $admin->id;
            $request->approved_at = now();
            $request->rejected_at = null;
            $request->decision_note = $this->nullableTrim($decisionNote);
            $request->save();

            AuditHelper::log(
                actionActivity: 'Room Transfer Request Approved',
                modulePage: 'Reservation Module',
                modelType: 'RoomTransferRequest',
                modelId: $request->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: ['status' => RoomTransferRequest::STATUS_PENDING_APPROVAL],
                newValues: [
                    'status' => $request->status,
                    'approved_by' => $admin->name,
                    'decision_note' => $request->decision_note,
                ],
                action: 'updated',
                actorUser: $admin
            );

            $this->queueDecisionEmail(
                booking: $booking->fresh(['primaryGuest']),
                heading: 'Room Transfer Request Approved',
                status: $request->status,
                messageBody: 'Your room transfer request has been approved. Our staff can now complete the room move for your active stay.',
                decisionNote: $request->decision_note,
            );

            $this->notifyRequester(
                $request,
                NotificationType::ROOM_TRANSFER_APPROVED,
                'Room Transfer Approved',
                "Room transfer for booking {$booking->reference_number} was approved by {$admin->name}.",
                $booking
            );

            return $request->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'bookingRoom.room',
                'currentRoom',
                'targetRoom',
                'requester',
                'approver',
                'completer',
            ]);
        }, 3);
    }

    /**
     * @throws \RuntimeException
     */
    public function reject(int $requestId, User $admin, ?string $decisionNote = null): RoomTransferRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $decisionNote) {
            $request = RoomTransferRequest::query()
                ->with('booking')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if ($request->status !== RoomTransferRequest::STATUS_PENDING_APPROVAL) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $request->status = RoomTransferRequest::STATUS_REJECTED;
            $request->approved_by = $admin->id;
            $request->approved_at = now();
            $request->rejected_at = now();
            $request->decision_note = $this->nullableTrim($decisionNote);
            $request->save();

            $booking = Booking::query()->find($request->booking_id);

            AuditHelper::log(
                actionActivity: 'Room Transfer Request Rejected',
                modulePage: 'Reservation Module',
                modelType: 'RoomTransferRequest',
                modelId: $request->id,
                recordAffected: $booking ? ('Booking ' . $booking->reference_number) : ('Request #' . $request->id),
                oldValues: ['status' => RoomTransferRequest::STATUS_PENDING_APPROVAL],
                newValues: [
                    'status' => $request->status,
                    'rejected_by' => $admin->name,
                    'decision_note' => $request->decision_note,
                ],
                action: 'updated',
                actorUser: $admin
            );

            $this->queueDecisionEmail(
                booking: $booking?->fresh(['primaryGuest']),
                heading: 'Room Transfer Request Rejected',
                status: $request->status,
                messageBody: 'Your room transfer request was reviewed and could not be approved. Your current room assignment remains unchanged.',
                decisionNote: $request->decision_note,
            );

            $this->notifyRequester(
                $request,
                NotificationType::ROOM_TRANSFER_REJECTED,
                'Room Transfer Rejected',
                'Room transfer for booking ' . ($booking?->reference_number ?? $request->booking_id) . " was rejected by {$admin->name}.",
                $booking
            );

            return $request->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'bookingRoom.room',
                'currentRoom',
                'targetRoom',
                'requester',
                'approver',
                'completer',
            ]);
        }, 3);
    }

    /**
     * @throws \RuntimeException
     */
    public function complete(int $requestId, User $admin, ?string $completionNote = null): array
    {
        return DB::transaction(function () use ($requestId, $admin, $completionNote) {
            $request = RoomTransferRequest::query()
                ->with(['booking', 'bookingRoom'])
                ->lockForUpdate()
                ->findOrFail($requestId);

            if ($request->status !== RoomTransferRequest::STATUS_APPROVED) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            if ($request->completed_at !== null) {
                throw new \RuntimeException('ALREADY_COMPLETED');
            }

            $booking = Booking::query()
                ->with(['bookingRooms', 'primaryGuest'])
                ->lockForUpdate()
                ->findOrFail($request->booking_id);

            $bookingRoom = $this->assertBookingTransferable(
                $booking,
                $request->booking_room_id !== null ? (int) $request->booking_room_id : null
            );

            if ((int) $bookingRoom->room_id !== (int) $request->current_room_id) {
                throw new \RuntimeException('ROOM_ALREADY_CHANGED');
            }

            $lockedRooms = Room::query()
                ->whereIn('id', [(int) $request->current_room_id, (int) $request->target_room_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $oldRoom = $lockedRooms->get((int) $request->current_room_id);
            $targetRoom = $lockedRooms->get((int) $request->target_room_id);

            if (! $oldRoom || ! $targetRoom) {
                throw new \RuntimeException('ROOM_NOT_FOUND');
            }

            $this->assertTargetRoomIsValid($booking, $bookingRoom, $targetRoom);

            $oldRate = (float) $bookingRoom->price_per_night;
            $oldSubtotal = (float) $bookingRoom->subtotal;
            $lineNights = max(1, (int) ($bookingRoom->nights ?? 1));

            $newRate = (float) $this->roomPricingService->resolveNightlyRateForRoom($targetRoom);
            $newSubtotal = round($newRate * $lineNights, 2);
            $lineDelta = round($newSubtotal - $oldSubtotal, 2);
            $oldTotalAmount = (float) $booking->total_amount;
            $newTotalAmount = round(max(0, $oldTotalAmount + $lineDelta), 2);

            $bookingRoom->update([
                'room_id' => $targetRoom->id,
                'price_per_night' => $newRate,
                'subtotal' => $newSubtotal,
            ]);

            $booking->update([
                'total_amount' => $newTotalAmount,
            ]);

            if ($oldRoom->status !== 'maintenance') {
                $oldRoom->update(['status' => 'cleaning']);
            }
            $this->roomStateService->recalculateMany([$oldRoom->id, $targetRoom->id]);

            $request->status = RoomTransferRequest::STATUS_COMPLETED;
            $request->completed_by = $admin->id;
            $request->completed_at = now();
            $request->completion_note = $this->nullableTrim($completionNote);
            $request->save();

            AuditHelper::log(
                actionActivity: 'Room Transfer Completed',
                modulePage: 'Reservation Module',
                modelType: 'RoomTransferRequest',
                modelId: $request->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: [
                    'room_id' => $oldRoom->id,
                    'room_number' => $oldRoom->room_number,
                    'line_price_per_night' => $oldRate,
                    'line_subtotal' => $oldSubtotal,
                    'booking_total_amount' => $oldTotalAmount,
                    'request_status' => RoomTransferRequest::STATUS_APPROVED,
                ],
                newValues: [
                    'room_id' => $targetRoom->id,
                    'room_number' => $targetRoom->room_number,
                    'line_price_per_night' => $newRate,
                    'line_subtotal' => $newSubtotal,
                    'line_delta' => $lineDelta,
                    'booking_total_amount' => $newTotalAmount,
                    'old_room_status' => $oldRoom->status,
                    'new_room_status' => $targetRoom->status,
                    'request_status' => RoomTransferRequest::STATUS_COMPLETED,
                    'completed_by' => $admin->name,
                ],
                action: 'updated',
                actorUser: $admin
            );

            $this->notifyRequester(
                $request,
                NotificationType::ROOM_TRANSFER_COMPLETED,
                'Room Transfer Completed',
                "Booking {$booking->reference_number} was transferred to Room {$targetRoom->room_number} by {$admin->name}.",
                $booking,
                $targetRoom->room_number
            );

            $booking->load(['primaryGuest', 'bookingRooms.room', 'payments']);
            $request->load([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'bookingRoom.room',
                'currentRoom',
                'targetRoom',
                'requester',
                'approver',
                'completer',
            ]);

            return [
                'request' => $request,
                'booking' => $booking,
            ];
        }, 3);
    }

    private function findBookingForUpdate(string|int $bookingKey): Booking
    {
        $query = Booking::query()
            ->with(['bookingRooms', 'bookingRooms.room'])
            ->lockForUpdate();

        if (is_numeric($bookingKey)) {
            return $query->where(function ($q) use ($bookingKey) {
                $q->where('id', (int) $bookingKey)
                    ->orWhere('reference_number', (string) $bookingKey);
            })->firstOrFail();
        }

        return $query->where('reference_number', (string) $bookingKey)->firstOrFail();
    }

    /**
     * @throws \RuntimeException
     */
    private function assertBookingTransferable(Booking $booking, ?int $expectedBookingRoomId = null): BookingRoom
    {
        if ($booking->booking_status !== 'checked_in') {
            throw new \RuntimeException('BOOKING_NOT_TRANSFERABLE');
        }

        if ($booking->bookingRooms->isEmpty()) {
            throw new \RuntimeException('BOOKING_ROOM_MISSING');
        }

        if ($expectedBookingRoomId === null) {
            if ($booking->bookingRooms->count() > 1) {
                throw new \RuntimeException('BOOKING_ROOM_REQUIRED');
            }
            $bookingRoom = $booking->bookingRooms->first();
        } else {
            $bookingRoom = $booking->bookingRooms
                ->first(fn ($row) => (int) $row->id === $expectedBookingRoomId);
        }

        if (!$bookingRoom) {
            throw new \RuntimeException('BOOKING_ROOM_MISMATCH');
        }

        return BookingRoom::query()
            ->with('room')
            ->lockForUpdate()
            ->findOrFail($bookingRoom->id);
    }

    /**
     * @throws \RuntimeException
     */
    private function assertNoOpenRequest(int $bookingId, int $bookingRoomId): void
    {
        $hasOpen = RoomTransferRequest::query()
            ->where('booking_id', $bookingId)
            ->where('booking_room_id', $bookingRoomId)
            ->whereIn('status', [
                RoomTransferRequest::STATUS_PENDING_APPROVAL,
                RoomTransferRequest::STATUS_APPROVED,
            ])
            ->whereNull('completed_at')
            ->lockForUpdate()
            ->exists();

        if ($hasOpen) {
            throw new \RuntimeException('OPEN_REQUEST_EXISTS');
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function assertTargetRoomIsValid(Booking $booking, BookingRoom $bookingRoom, Room $targetRoom): void
    {
        if ((int) $targetRoom->id === (int) $bookingRoom->room_id) {
            throw new \RuntimeException('TARGET_SAME_AS_CURRENT');
        }

        $alreadyAssignedToBooking = BookingRoom::query()
            ->where('booking_id', $booking->id)
            ->where('id', '!=', $bookingRoom->id)
            ->where('room_id', $targetRoom->id)
            ->where(function ($activeQ) {
                $activeQ->whereNull('room_status')
                    ->orWhere('room_status', 'active');
            })
            ->lockForUpdate()
            ->exists();

        if ($alreadyAssignedToBooking) {
            throw new \RuntimeException('TARGET_ALREADY_ASSIGNED_TO_BOOKING');
        }

        if (in_array($targetRoom->status, ['occupied', 'maintenance', 'cleaning'], true)) {
            throw new \RuntimeException('TARGET_NOT_AVAILABLE_STATUS');
        }

        if ((int) $targetRoom->capacity < (int) $booking->number_of_guests) {
            throw new \RuntimeException('TARGET_CAPACITY_MISMATCH');
        }

        $hasOverlap = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->where('booking_rooms.room_id', $targetRoom->id)
            ->where('bookings.id', '!=', $booking->id)
            ->where(function ($activeQ) {
                $activeQ->whereNull('booking_rooms.room_status')
                    ->orWhere('booking_rooms.room_status', 'active');
            })
            ->whereIn('bookings.booking_status', ['pending', 'confirmed', 'checked_in'])
            ->where('bookings.check_in', '<', $booking->check_out)
            ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$booking->check_in])
            ->exists();

        if ($hasOverlap) {
            throw new \RuntimeException('TARGET_OVERLAP');
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : null;
    }

    private function notifyActiveAdmins(NotificationType $type, string $title, string $message, array $data): void
    {
        User::active()
            ->where('role', 'admin')
            ->pluck('id')
            ->each(fn ($userId) => NotificationHelper::send((int) $userId, $type, $title, $message, $data));
    }

    private function notifyRequester(
        RoomTransferRequest $request,
        NotificationType $type,
        string $title,
        string $message,
        ?Booking $booking,
        ?string $roomNumber = null
    ): void {
        $requesterId = (int) $request->requested_by;

        if ($requesterId <= 0 || ! User::active()->whereKey($requesterId)->exists()) {
            return;
        }

        NotificationHelper::send($requesterId, $type, $title, $message, [
            'request_id' => $request->id,
            'booking_id' => $booking?->id ?? $request->booking_id,
            'room' => $roomNumber,
            'status' => $request->status,
            'reason' => $request->decision_note,
        ]);
    }

    private function queueDecisionEmail(
        ?Booking $booking,
        string $heading,
        string $status,
        string $messageBody,
        ?string $decisionNote = null
    ): void {
        $guestEmail = $booking?->primaryGuest?->email;
        if (!$booking || !$guestEmail) {
            return;
        }

        Mail::to($guestEmail)->queue(new BookingWorkflowDecisionMail(
            booking: $booking,
            heading: $heading,
            status: $status,
            messageBody: $messageBody,
            decisionNote: $decisionNote,
        ));
    }
}
