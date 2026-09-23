<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Helpers\NotificationHelper;
use App\Mail\BookingWorkflowDecisionMail;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Models\Rebooking;
use App\Models\RebookingRoomHold;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class RebookingRequestService
{
    public function __construct(
        private RoomStateService $roomStateService,
        private RoomPricingService $roomPricingService,
        private CancellationApprovalService $cancellationApprovalService,
        private DownpaymentService $downpaymentService
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public function createGuestRequest(
        string|int $bookingKey,
        array $payload,
        ?string $actorLabel = 'Guest Portal'
    ): Rebooking {
        return DB::transaction(function () use ($bookingKey, $payload, $actorLabel) {
            $booking = $this->findBookingForUpdate($bookingKey);
            return $this->createGuestRequestForLockedBooking($booking, $payload, $actorLabel);
        }, 3);
    }

    /**
     * @return array<int, Rebooking>
     *
     * @throws \RuntimeException
     */
    public function createGuestRequestBatch(
        string|int $bookingKey,
        array $payload,
        ?string $actorLabel = 'Guest Portal'
    ): array {
        return DB::transaction(function () use ($bookingKey, $payload, $actorLabel) {
            $booking = $this->findBookingForUpdate($bookingKey);
            return $this->createGuestRequestBatchForLockedBooking($booking, $payload, $actorLabel);
        }, 3);
    }

    /**
     * @throws \RuntimeException
     */
    public function createGuestRequestForLockedBooking(
        Booking $booking,
        array $payload,
        ?string $actorLabel = 'Guest Portal'
    ): Rebooking {
        $created = $this->createGuestRequestBatchForLockedBooking($booking, $payload, $actorLabel);
        return $created[0];
    }

    /**
     * @return array<int, Rebooking>
     *
     * @throws \RuntimeException
     */
    public function createGuestRequestBatchForLockedBooking(
        Booking $booking,
        array $payload,
        ?string $actorLabel = 'Guest Portal'
    ): array {
        $this->assertBookingRequestable($booking);
        $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking($booking, 'rebooking_request_create');
        $this->assertNoOpenRequest($booking->id);

        $alreadyRebooked = (bool) $booking->has_been_rebooked
            || $booking->rebookingsAsOriginal()
                ->where('status', Rebooking::STATUS_APPROVED)
                ->exists();
        if ($alreadyRebooked) {
            throw new \RuntimeException('BOOKING_ALREADY_REBOOKED');
        }

        $roomChanges = $this->normalizeRoomChangesPayload($payload);
        if (count($roomChanges) === 0) {
            throw new \RuntimeException('ROOM_CHANGES_REQUIRED');
        }

        $currentRoomIds = collect($booking->bookingRooms()->pluck('room_id')->all())
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $preparedChanges = [];
        $seenBookingRoomIds = [];
        $requestedRoomIds = [];
        $seenRequestedRoomIds = [];

        foreach ($roomChanges as $roomChange) {
            $targetBookingRoom = $this->resolveTargetBookingRoomForLockedBooking($booking, [
                'booking_room_id' => $roomChange['booking_room_id'] ?? null,
                'original_booking_room_id' => $roomChange['booking_room_id'] ?? null,
            ]);

            $targetBookingRoomId = (int) $targetBookingRoom->id;
            if (in_array($targetBookingRoomId, $seenBookingRoomIds, true)) {
                throw new \RuntimeException('BOOKING_ROOM_LINE_DUPLICATE');
            }
            $seenBookingRoomIds[] = $targetBookingRoomId;

            $requestedRoomId = (int) ($roomChange['requested_room_id'] ?? 0);
            if ($requestedRoomId <= 0) {
                throw new \RuntimeException('NEW_ROOM_REQUIRED');
            }

            if (in_array($requestedRoomId, $currentRoomIds, true)) {
                throw new \RuntimeException('NEW_ROOM_SAME_AS_CURRENT');
            }

            if (in_array($requestedRoomId, $seenRequestedRoomIds, true)) {
                throw new \RuntimeException('REQUESTED_ROOM_DUPLICATE_IN_REQUEST');
            }
            $seenRequestedRoomIds[] = $requestedRoomId;
            $requestedRoomIds[] = $requestedRoomId;

            $preparedChanges[] = [
                'target_booking_room' => $targetBookingRoom,
                'requested_room_id' => $requestedRoomId,
            ];
        }

        $requestedRoomsById = Room::query()
            ->whereIn('id', array_values(array_unique($requestedRoomIds)))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($preparedChanges as $changeIndex => $prepared) {
            $newRoomId = (int) $prepared['requested_room_id'];
            $newRoom = $requestedRoomsById->get($newRoomId);
            if (!$newRoom) {
                throw new \RuntimeException('REQUESTED_ROOM_NOT_FOUND');
            }

            $this->assertRequestedRoomOperationalForBooking($booking, $newRoom);
            $this->assertRequestedRoomAvailableForBooking($booking, $newRoomId);
            $preparedChanges[$changeIndex]['requested_room'] = $newRoom;
        }

        $reasonText = trim((string) ($payload['reason'] ?? ''));
        $createdRows = [];
        $groupId = null;

        foreach ($preparedChanges as $prepared) {
            /** @var BookingRoom $targetBookingRoom */
            $targetBookingRoom = $prepared['target_booking_room'];
            /** @var Room $newRoom */
            $newRoom = $prepared['requested_room'];
            $newRoomId = (int) $newRoom->id;

            $roomLabel = "Room {$newRoom->room_number} ({$newRoom->room_type})";
            $fromRoomLabel = $targetBookingRoom->room
                ? "Room {$targetBookingRoom->room->room_number} ({$targetBookingRoom->room->room_type})"
                : "Room Allocation #{$targetBookingRoom->id}";
            $reason = $reasonText === ''
                ? "Requested room change from {$fromRoomLabel} to {$roomLabel}."
                : "Requested room change from {$fromRoomLabel} to {$roomLabel}. Guest note: {$reasonText}";

            try {
                $rebooking = Rebooking::create([
                    'rebooking_group_id' => $groupId,
                    'is_group_leader' => $groupId === null,
                    'original_booking_id' => $booking->id,
                    'original_booking_room_id' => (int) $targetBookingRoom->id,
                    'original_room_id' => (int) $targetBookingRoom->room_id,
                    'new_booking_id' => null,
                    'requested_room_id' => $newRoomId,
                    'reason' => $reason,
                    'requested_by' => null,
                    'status' => Rebooking::STATUS_PENDING,
                    'finalized_at' => null,
                ]);
            } catch (QueryException $e) {
                if ($this->isOpenRequestUniqueViolation($e)) {
                    throw new \RuntimeException('OPEN_REQUEST_EXISTS');
                }

                throw $e;
            }

            if ($groupId === null) {
                $groupId = (int) $rebooking->id;
                $rebooking->update(['rebooking_group_id' => $groupId]);
                $rebooking = $rebooking->fresh();
            }

            AuditHelper::log(
                actionActivity: 'Rebooking Request Created',
                modulePage: 'Rebooking Module',
                modelType: 'Rebooking',
                modelId: $rebooking->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: null,
                newValues: [
                    'booking_id' => $booking->id,
                    'rebooking_group_id' => $groupId,
                    'booking_room_id' => (int) $targetBookingRoom->id,
                    'requested_room_id' => $newRoomId,
                    'status' => $rebooking->status,
                ],
                action: 'created',
                actorLabel: $actorLabel
            );

            $createdRows[] = (int) $rebooking->id;
        }

        return Rebooking::query()
            ->whereIn('id', $createdRows)
            ->orderBy('id')
            ->get()
            ->map(fn (Rebooking $row) => $row->fresh([
            'originalBooking.primaryGuest',
            'originalBooking.bookingRooms.room',
            'newBooking.primaryGuest',
            'newBooking.bookingRooms.room',
            'originalBookingRoom.room',
            'originalRoom',
            'requestedRoom',
            ]))
            ->all();
    }

    /**
     * Return the financial decision the staff UI should present before an action is attempted.
     */
    public function financialAssessment(int|Rebooking $request): array
    {
        $selected = $request instanceof Rebooking
            ? $request
            : Rebooking::query()->findOrFail($request);
        $groupId = (int) ($selected->rebooking_group_id ?: $selected->id);
        $rows = Rebooking::query()
            ->with(['originalBookingRoom.room', 'requestedRoom', 'adjustmentPayment.manualGcashSubmissions'])
            ->where('original_booking_id', (int) $selected->original_booking_id)
            ->where(function ($query) use ($groupId) {
                $query->where('rebooking_group_id', $groupId)->orWhere('id', $groupId);
            })
            ->orderBy('id')
            ->get();
        $leader = $rows->firstWhere('is_group_leader', true) ?? $rows->first() ?? $selected;
        $booking = Booking::query()
            ->with(['bookingRooms.room', 'payments'])
            ->find($selected->original_booking_id);

        if (! $booking) {
            return ['type' => 'blocked', 'status' => 'booking_missing', 'approval_ready' => false];
        }

        if ($leader->finalized_at && $leader->original_total !== null && $leader->projected_total !== null) {
            return [
                'type' => 'none',
                'status' => $leader->financial_status ?: Rebooking::FINANCIAL_READY,
                'amount' => round((float) ($leader->adjustment_amount ?? 0), 2),
                'original_total' => round((float) $leader->original_total, 2),
                'projected_total' => round((float) $leader->projected_total, 2),
                'price_difference' => round((float) ($leader->price_difference ?? 0), 2),
                'net_paid' => round(Payment::netAmountFrom($booking->payments), 2),
                'required_downpayment' => round((float) $this->downpaymentService->calculate(
                    (float) $leader->projected_total,
                    $booking->downpayment_percentage !== null ? (float) $booking->downpayment_percentage : null
                ), 2),
                'payment_id' => $leader->adjustment_payment_id,
                'payment_due_at' => null,
                'approval_ready' => false,
            ];
        }

        $originalTotal = round((float) $booking->total_amount, 2);
        $projectedTotal = $originalTotal;
        foreach ($rows as $row) {
            $line = $booking->bookingRooms->firstWhere('id', (int) $row->original_booking_room_id);
            $room = $row->requestedRoom;
            if (! $line || ! $room) {
                return ['type' => 'blocked', 'status' => 'room_data_missing', 'approval_ready' => false];
            }

            $newRate = $this->roomPricingService->resolveNightlyRateForRoom($room);
            $newSubtotal = round($newRate * max(1, (int) $line->nights), 2);
            $projectedTotal = round(max(0, $projectedTotal + $newSubtotal - (float) $line->subtotal), 2);
        }

        $netPaid = Payment::netAmountFrom($booking->payments);
        $requiredDownpayment = $this->downpaymentService->calculate(
            $projectedTotal,
            $booking->downpayment_percentage !== null ? (float) $booking->downpayment_percentage : null
        );
        $difference = round($projectedTotal - $originalTotal, 2);
        $additional = round(max(0, $requiredDownpayment - $netPaid), 2);
        $refund = round(max(0, $netPaid - $projectedTotal), 2);
        $payment = $leader->adjustmentPayment;

        $status = Rebooking::FINANCIAL_READY;
        $type = 'none';
        $amount = 0.0;
        if ($payment && $payment->payment_status === 'pending') {
            $protectedProof = $payment->manualGcashSubmissions
                ->contains(fn ($submission) => in_array($submission->status, ['pending_verification', 'escalated'], true));
            if ($payment->payment_due_at?->isPast() && ! $protectedProof) {
                $status = Rebooking::FINANCIAL_ADDITIONAL_PAYMENT_REQUIRED;
                $type = 'additional_payment';
                $amount = $additional > 0.009 ? $additional : (float) $payment->amount;
            } else {
                $status = in_array($payment->resolvedLifecycleStatus(), [
                Payment::LIFECYCLE_PROOF_SUBMITTED,
                Payment::LIFECYCLE_PENDING_VERIFICATION,
                Payment::LIFECYCLE_PAID_UNDER_REVIEW,
                ], true) || $protectedProof
                ? Rebooking::FINANCIAL_PAYMENT_UNDER_REVIEW
                : Rebooking::FINANCIAL_AWAITING_PAYMENT;
                $type = 'additional_payment';
                $amount = (float) $payment->amount;
            }
        } elseif ($additional > 0.009) {
            $status = Rebooking::FINANCIAL_ADDITIONAL_PAYMENT_REQUIRED;
            $type = 'additional_payment';
            $amount = $additional;
        } elseif ($refund > 0.009) {
            $status = Rebooking::FINANCIAL_REFUND_REVIEW;
            $type = 'refund';
            $amount = $refund;
        }

        return [
            'type' => $type,
            'status' => $status,
            'amount' => round($amount, 2),
            'original_total' => $originalTotal,
            'projected_total' => $projectedTotal,
            'price_difference' => $difference,
            'net_paid' => round($netPaid, 2),
            'required_downpayment' => round((float) $requiredDownpayment, 2),
            'payment_id' => $payment?->id,
            'payment_due_at' => $payment?->payment_due_at?->toIso8601String(),
            'approval_ready' => $status === Rebooking::FINANCIAL_READY
                && in_array($leader->status, [Rebooking::STATUS_PENDING, Rebooking::STATUS_UNDER_REVIEW], true),
        ];
    }

    /**
     * @throws \RuntimeException
     */
    public function approve(int $requestId, User $actor, ?string $note = null): Rebooking
    {
        return DB::transaction(function () use ($requestId, $actor, $note) {
            $selectedRebooking = Rebooking::query()
                ->with([
                    'originalBooking.primaryGuest',
                    'originalBooking.bookingRooms.room',
                    'originalBookingRoom.room',
                    'requestedRoom',
                ])
                ->lockForUpdate()
                ->findOrFail($requestId);

            $groupRows = $this->resolveGroupedRowsForDecision($selectedRebooking);

            $hasInvalidGroupStatus = $groupRows->contains(fn (Rebooking $row) =>
                ! in_array($row->status, [Rebooking::STATUS_PENDING, Rebooking::STATUS_UNDER_REVIEW], true)
                || $row->finalized_at !== null
            );
            if ($hasInvalidGroupStatus) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $booking = Booking::query()
                ->with(['primaryGuest', 'bookingRooms.room'])
                ->lockForUpdate()
                ->find($selectedRebooking->original_booking_id);

            if (!$booking) {
                throw new \RuntimeException('BOOKING_NOT_FOUND');
            }

            if ((string) $booking->booking_status !== 'confirmed') {
                throw new \RuntimeException('BOOKING_NOT_CONFIRMED');
            }
            $this->assertBookingPaymentsSettled($booking, false);
            $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking($booking, 'rebooking_approve');
            $approvalAssessment = $this->financialAssessment($selectedRebooking);
            $this->assertApprovalFinanciallySafe($booking, $groupRows);

            $decisionNote = $this->nullableTrim($note);
            $runningTotalAmount = (float) $booking->total_amount;
            $affectedRoomIds = [];

            foreach ($groupRows as $rebooking) {
                $targetBookingRoom = null;
                if ($rebooking->original_booking_room_id) {
                    $targetBookingRoom = $booking->bookingRooms
                        ->firstWhere('id', (int) $rebooking->original_booking_room_id);

                    if (!$targetBookingRoom) {
                        throw new \RuntimeException('BOOKING_ROOM_LINE_MISSING');
                    }
                } elseif ($booking->bookingRooms->count() === 1) {
                    $targetBookingRoom = $booking->bookingRooms->first();
                } else {
                    throw new \RuntimeException('LEGACY_MULTI_ROOM_REBOOKING_UNSAFE');
                }

                $targetBookingRoom = BookingRoom::query()
                    ->with('room')
                    ->whereKey((int) $targetBookingRoom->id)
                    ->lockForUpdate()
                    ->first();
                if (!$targetBookingRoom) {
                    throw new \RuntimeException('BOOKING_ROOM_LINE_MISSING');
                }

                $requestedRoom = Room::query()
                    ->whereKey((int) $rebooking->requested_room_id)
                    ->lockForUpdate()
                    ->first();
                if (!$requestedRoom) {
                    throw new \RuntimeException('REQUESTED_ROOM_NOT_FOUND');
                }

                $oldRoomId = (int) $targetBookingRoom->room_id;
                $newRoomId = (int) $requestedRoom->id;

                if ($newRoomId === $oldRoomId) {
                    throw new \RuntimeException('REQUESTED_ROOM_ALREADY_ASSIGNED');
                }

                $alreadyAssignedInBooking = BookingRoom::query()
                    ->where('booking_id', $booking->id)
                    ->where('id', '!=', (int) $targetBookingRoom->id)
                    ->where('room_id', $newRoomId)
                    ->exists();
                if ($alreadyAssignedInBooking) {
                    throw new \RuntimeException('REQUESTED_ROOM_DUPLICATE_IN_BOOKING');
                }

                $this->assertRequestedRoomOperationalForBooking($booking, $requestedRoom);
                $this->assertRequestedRoomAvailableForBooking($booking, $newRoomId);

                $oldRate = (float) $targetBookingRoom->price_per_night;
                $oldSubtotal = (float) $targetBookingRoom->subtotal;
                $lineNights = max(1, (int) $targetBookingRoom->nights);
                $newRate = $this->roomPricingService->resolveNightlyRateForRoom($requestedRoom);
                $newSubtotal = round($newRate * $lineNights, 2);
                $lineDelta = round($newSubtotal - $oldSubtotal, 2);
                $oldTotalAmount = $runningTotalAmount;
                $runningTotalAmount = round(max(0, $runningTotalAmount + $lineDelta), 2);

                $oldRoom = $targetBookingRoom->room;
                $oldRoomLabel = $oldRoom
                    ? "{$oldRoom->room_type} {$oldRoom->room_number}"
                    : "Room #{$oldRoomId}";
                $newRoomLabel = "{$requestedRoom->room_type} {$requestedRoom->room_number}";

                if (!$rebooking->original_booking_room_id) {
                    $rebooking->original_booking_room_id = (int) $targetBookingRoom->id;
                }
                if (!$rebooking->original_room_id) {
                    $rebooking->original_room_id = $oldRoomId;
                }

                $targetBookingRoom->update([
                    'room_id' => $newRoomId,
                    'price_per_night' => $newRate,
                    'subtotal' => $newSubtotal,
                ]);

                $rebooking->update([
                    'status' => Rebooking::STATUS_APPROVED,
                    'financial_status' => $rebooking->financial_status === Rebooking::FINANCIAL_REFUND_COMPLETED
                        ? Rebooking::FINANCIAL_REFUND_COMPLETED
                        : Rebooking::FINANCIAL_READY,
                    'original_total' => $approvalAssessment['original_total'] ?? $booking->total_amount,
                    'projected_total' => $approvalAssessment['projected_total'] ?? $runningTotalAmount,
                    'price_difference' => $approvalAssessment['price_difference'] ?? 0,
                    'adjustment_amount' => $rebooking->adjustment_amount ?? ($approvalAssessment['amount'] ?? 0),
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'decision_note' => $decisionNote,
                    'finalized_at' => now(),
                ]);

                $affectedRoomIds[] = $oldRoomId;
                $affectedRoomIds[] = $newRoomId;

                AuditHelper::log(
                    actionActivity: 'Rebooking Request Approved',
                    modulePage: 'Rebooking Module',
                    modelType: 'Rebooking',
                    modelId: $rebooking->id,
                    recordAffected: 'Booking ' . $booking->reference_number,
                    oldValues: ['status' => Rebooking::STATUS_PENDING],
                    newValues: [
                        'status' => $rebooking->status,
                        'approved_by' => $actor->name,
                        'decision_note' => $rebooking->decision_note,
                        'finalized_at' => $rebooking->finalized_at?->toDateTimeString(),
                    ],
                    action: 'updated',
                    actorUser: $actor
                );

                AuditHelper::log(
                    actionActivity: 'Rebooking Finalized - Room Allocation Updated',
                    modulePage: 'Rebooking Module',
                    modelType: 'Booking',
                    modelId: $booking->id,
                    recordAffected: 'Booking ' . $booking->reference_number,
                    oldValues: [
                        'booking_room_id' => (int) $targetBookingRoom->id,
                        'room_id' => $oldRoomId,
                        'room_label' => $oldRoomLabel,
                        'line_price_per_night' => $oldRate,
                        'line_subtotal' => $oldSubtotal,
                        'booking_total_amount' => $oldTotalAmount,
                    ],
                    newValues: [
                        'booking_room_id' => (int) $targetBookingRoom->id,
                        'room_id' => $newRoomId,
                        'room_label' => $newRoomLabel,
                        'line_price_per_night' => $newRate,
                        'line_subtotal' => $newSubtotal,
                        'line_delta' => $lineDelta,
                        'booking_total_amount' => $runningTotalAmount,
                        'rebooking_id' => $rebooking->id,
                    ],
                    action: 'updated',
                    actorUser: $actor
                );

                $booking->setRelation(
                    'bookingRooms',
                    $booking->bookingRooms->map(function ($line) use ($targetBookingRoom, $newRoomId, $newRate, $newSubtotal) {
                        if ((int) $line->id !== (int) $targetBookingRoom->id) {
                            return $line;
                        }

                        $line->room_id = $newRoomId;
                        $line->price_per_night = $newRate;
                        $line->subtotal = $newSubtotal;
                        return $line;
                    })
                );
            }

            $booking->update([
                'total_amount' => $runningTotalAmount,
                'has_been_rebooked' => true,
            ]);

            $this->roomStateService->recalculateMany(array_values(array_unique($affectedRoomIds)));
            RebookingRoomHold::query()
                ->whereIn('rebooking_id', $groupRows->pluck('id')->all())
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            $this->queueDecisionEmail(
                booking: $booking->fresh(['primaryGuest']),
                heading: 'Rebooking Request Approved',
                status: Rebooking::STATUS_APPROVED,
                messageBody: count($groupRows) > 1
                    ? 'Your multi-room rebooking request has been approved. Your booking now reflects all approved room allocation updates for the same stay dates.'
                    : 'Your room rebooking request has been approved. Your booking now reflects the updated room allocation for the same stay dates.',
                decisionNote: $decisionNote,
            );

            try {
                NotificationHelper::rebookingReviewed([
                    'rebooking_id' => $selectedRebooking->id,
                    'booking_id' => $booking->reference_number,
                    'guest_name' => $booking->primaryGuest?->name,
                    'reviewed_by' => $actor->name,
                    'decision_note' => $decisionNote,
                ], true);
            } catch (\Throwable $e) {
                // Keep rebooking workflow non-blocking when notification write fails.
            }

            $leader = $groupRows->firstWhere('is_group_leader', true) ?? $groupRows->first();

            return $leader->fresh([
                'originalBooking.primaryGuest',
                'originalBooking.bookingRooms.room',
                'newBooking.primaryGuest',
                'newBooking.bookingRooms.room',
                'originalBookingRoom.room',
                'originalRoom',
                'requestedRoom',
            ]);
        }, 3);
    }

    /**
     * @throws \RuntimeException
     */
    public function reject(int $requestId, User $actor, ?string $note = null): Rebooking
    {
        return DB::transaction(function () use ($requestId, $actor, $note) {
            $selectedRebooking = Rebooking::query()
                ->with(['originalBooking.primaryGuest'])
                ->lockForUpdate()
                ->findOrFail($requestId);

            $groupRows = $this->resolveGroupedRowsForDecision($selectedRebooking);
            $hasInvalidGroupStatus = $groupRows->contains(fn (Rebooking $row) =>
                ! in_array($row->status, [Rebooking::STATUS_PENDING, Rebooking::STATUS_UNDER_REVIEW, Rebooking::STATUS_AWAITING_PAYMENT], true)
                || $row->finalized_at !== null
            );
            if ($hasInvalidGroupStatus) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $leaderForPayment = $groupRows->firstWhere('is_group_leader', true) ?? $groupRows->first();
            $adjustmentPayment = $leaderForPayment?->adjustmentPayment()->lockForUpdate()->first();
            if ($adjustmentPayment?->payment_status === 'completed') {
                throw new \RuntimeException('VERIFIED_ADJUSTMENT_PAYMENT_EXISTS');
            }
            if ($adjustmentPayment?->payment_status === 'pending') {
                if ($adjustmentPayment->manualGcashSubmissions()
                    ->whereIn('status', ['pending_verification', 'escalated'])->exists()) {
                    throw new \RuntimeException('ADJUSTMENT_PAYMENT_UNDER_REVIEW');
                }
                $adjustmentPayment->update([
                    'payment_status' => 'failed',
                    'lifecycle_status' => Payment::LIFECYCLE_FAILED,
                    'lifecycle_message' => 'Rebooking request was rejected before payment completion.',
                ]);
            }

            $decisionNote = $this->nullableTrim($note);
            $bookingRef = $selectedRebooking->originalBooking?->reference_number ?? ('#' . $selectedRebooking->original_booking_id);

            foreach ($groupRows as $rebooking) {
                $rebooking->update([
                    'status' => Rebooking::STATUS_REJECTED,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'decision_note' => $decisionNote,
                    'finalized_at' => now(),
                ]);

                AuditHelper::log(
                    actionActivity: 'Rebooking Request Rejected',
                    modulePage: 'Rebooking Module',
                    modelType: 'Rebooking',
                    modelId: $rebooking->id,
                    recordAffected: 'Booking ' . $bookingRef,
                    oldValues: ['status' => Rebooking::STATUS_PENDING],
                    newValues: [
                        'status' => $rebooking->status,
                        'rejected_by' => $actor->name,
                        'decision_note' => $rebooking->decision_note,
                        'finalized_at' => $rebooking->finalized_at?->toDateTimeString(),
                    ],
                    action: 'updated',
                    actorUser: $actor
                );
            }

            RebookingRoomHold::query()
                ->whereIn('rebooking_id', $groupRows->pluck('id')->all())
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            $this->queueDecisionEmail(
                booking: $selectedRebooking->fresh(['originalBooking.primaryGuest'])->originalBooking,
                heading: 'Rebooking Request Rejected',
                status: Rebooking::STATUS_REJECTED,
                messageBody: count($groupRows) > 1
                    ? 'Your multi-room rebooking request was reviewed and could not be approved. Your current booking remains unchanged.'
                    : 'Your rebooking request was reviewed and could not be approved. Your current booking remains unchanged.',
                decisionNote: $decisionNote,
            );

            try {
                NotificationHelper::rebookingReviewed([
                    'rebooking_id' => $selectedRebooking->id,
                    'booking_id' => $bookingRef,
                    'guest_name' => $selectedRebooking->originalBooking?->primaryGuest?->name,
                    'reviewed_by' => $actor->name,
                    'decision_note' => $decisionNote,
                ], false);
            } catch (\Throwable $e) {
                // Keep rebooking workflow non-blocking when notification write fails.
            }

            $leader = $groupRows->firstWhere('is_group_leader', true) ?? $groupRows->first();

            return $leader->fresh([
                'originalBooking.primaryGuest',
                'originalBooking.bookingRooms.room',
                'newBooking.primaryGuest',
                'newBooking.bookingRooms.room',
                'originalBookingRoom.room',
                'originalRoom',
                'requestedRoom',
            ]);
        }, 3);
    }

    public function findOpenRequest(int $bookingId): ?Rebooking
    {
        return Rebooking::query()
            ->where('original_booking_id', $bookingId)
            ->whereIn('status', Rebooking::OPEN_STATUSES)
            ->whereNull('finalized_at')
            ->latest('id')
            ->first();
    }

    private function findBookingForUpdate(string|int $bookingKey): Booking
    {
        $query = Booking::query()->with(['bookingRooms.room'])->lockForUpdate();

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
    private function assertBookingRequestable(Booking $booking): void
    {
        if ((string) $booking->booking_status !== 'confirmed') {
            throw new \RuntimeException('BOOKING_NOT_CONFIRMED');
        }

        $this->assertBookingPaymentsSettled($booking);
    }

    private function assertBookingPaymentsSettled(Booking $booking, bool $enforceCurrentDownpayment = true): void
    {
        $payments = $booking->payments()
            ->lockForUpdate()
            ->get();

        $openLifecycleStatuses = [
            Payment::LIFECYCLE_PENDING,
            Payment::LIFECYCLE_AWAITING_PAYMENT,
            Payment::LIFECYCLE_PROOF_SUBMITTED,
            Payment::LIFECYCLE_PENDING_VERIFICATION,
            Payment::LIFECYCLE_REJECTED,
            Payment::LIFECYCLE_AUTHORIZED,
            Payment::LIFECYCLE_CAPTURE_PENDING,
            Payment::LIFECYCLE_CAPTURE_UNKNOWN,
            Payment::LIFECYCLE_PAID_UNDER_REVIEW,
            Payment::LIFECYCLE_REFUND_REQUIRED,
        ];

        $hasOpenPayment = $payments->contains(function (Payment $payment) use ($openLifecycleStatuses) {
            if ((string) $payment->payment_type === 'refund') {
                return false;
            }

            return (string) $payment->payment_status === 'pending'
                || in_array($payment->resolvedLifecycleStatus(), $openLifecycleStatuses, true);
        });

        if ($hasOpenPayment) {
            throw new \RuntimeException('BOOKING_PAYMENT_NOT_SETTLED');
        }

        if (! $enforceCurrentDownpayment) {
            return;
        }

        $requiredDownpayment = $this->downpaymentService->calculate(
            (float) $booking->total_amount,
            $booking->downpayment_percentage !== null ? (float) $booking->downpayment_percentage : null
        );

        if ($this->netCompletedPaymentAmount($payments) + 0.009 < $requiredDownpayment) {
            throw new \RuntimeException('BOOKING_PAYMENT_NOT_SETTLED');
        }
    }

    private function assertApprovalFinanciallySafe(Booking $booking, $groupRows): void
    {
        $projectedTotal = (float) $booking->total_amount;
        $requestedRoomIds = [];

        foreach ($groupRows as $rebooking) {
            if ($rebooking->original_booking_room_id) {
                $targetBookingRoom = BookingRoom::query()
                    ->where('booking_id', $booking->id)
                    ->whereKey((int) $rebooking->original_booking_room_id)
                    ->lockForUpdate()
                    ->first();
            } elseif ($booking->bookingRooms->count() === 1) {
                $targetBookingRoom = BookingRoom::query()
                    ->where('booking_id', $booking->id)
                    ->whereKey((int) $booking->bookingRooms->first()->id)
                    ->lockForUpdate()
                    ->first();
            } else {
                throw new \RuntimeException('LEGACY_MULTI_ROOM_REBOOKING_UNSAFE');
            }

            if (!$targetBookingRoom) {
                throw new \RuntimeException('BOOKING_ROOM_LINE_MISSING');
            }

            $requestedRoom = Room::query()
                ->whereKey((int) $rebooking->requested_room_id)
                ->lockForUpdate()
                ->first();

            if (!$requestedRoom) {
                throw new \RuntimeException('REQUESTED_ROOM_NOT_FOUND');
            }

            $requestedRoomId = (int) $requestedRoom->id;
            if (in_array($requestedRoomId, $requestedRoomIds, true)) {
                throw new \RuntimeException('REQUESTED_ROOM_DUPLICATE_IN_REQUEST');
            }
            $requestedRoomIds[] = $requestedRoomId;

            if ((int) $targetBookingRoom->room_id === $requestedRoomId) {
                throw new \RuntimeException('REQUESTED_ROOM_ALREADY_ASSIGNED');
            }

            $alreadyAssignedInBooking = BookingRoom::query()
                ->where('booking_id', $booking->id)
                ->where('id', '!=', (int) $targetBookingRoom->id)
                ->where('room_id', $requestedRoomId)
                ->exists();

            if ($alreadyAssignedInBooking) {
                throw new \RuntimeException('REQUESTED_ROOM_DUPLICATE_IN_BOOKING');
            }

            $this->assertRequestedRoomOperationalForBooking($booking, $requestedRoom);
            $this->assertRequestedRoomAvailableForBooking($booking, $requestedRoomId);

            $newRate = $this->roomPricingService->resolveNightlyRateForRoom($requestedRoom);
            $newSubtotal = round($newRate * max(1, (int) $targetBookingRoom->nights), 2);
            $projectedTotal = round(max(0, $projectedTotal + $newSubtotal - (float) $targetBookingRoom->subtotal), 2);
        }

        $payments = $booking->payments()
            ->lockForUpdate()
            ->get();
        $netPaid = $this->netCompletedPaymentAmount($payments);
        $requiredDownpayment = $this->downpaymentService->calculate(
            $projectedTotal,
            $booking->downpayment_percentage !== null ? (float) $booking->downpayment_percentage : null
        );

        if ($netPaid + 0.009 < $requiredDownpayment) {
            throw new \RuntimeException('ADDITIONAL_PAYMENT_REQUIRED:' . number_format($requiredDownpayment - $netPaid, 2, '.', ''));
        }

        if ($netPaid - $projectedTotal > 0.009) {
            throw new \RuntimeException('REFUND_REVIEW_REQUIRED:' . number_format($netPaid - $projectedTotal, 2, '.', ''));
        }
    }

    private function netCompletedPaymentAmount($payments): float
    {
        $paid = (float) $payments
            ->filter(fn (Payment $payment) => $payment->payment_status === 'completed' && $payment->payment_type !== 'refund')
            ->sum('amount');
        $refunded = (float) $payments
            ->filter(fn (Payment $payment) => $payment->payment_type === 'refund'
                && in_array($payment->payment_status, ['completed', 'refunded'], true))
            ->sum('amount');

        return round(max(0, $paid - $refunded), 2);
    }

    /**
     * @throws \RuntimeException
     */
    private function assertNoOpenRequest(int $bookingId): void
    {
        $hasOpen = Rebooking::query()
            ->where('original_booking_id', $bookingId)
            ->whereIn('status', Rebooking::OPEN_STATUSES)
            ->whereNull('finalized_at')
            ->lockForUpdate()
            ->exists();

        if ($hasOpen) {
            throw new \RuntimeException('OPEN_REQUEST_EXISTS');
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function resolveTargetBookingRoomForLockedBooking(Booking $booking, array $payload): BookingRoom
    {
        $roomLineIdInput = $payload['booking_room_id'] ?? ($payload['original_booking_room_id'] ?? null);
        $targetBookingRoom = null;

        if ($roomLineIdInput !== null && $roomLineIdInput !== '') {
            $targetBookingRoom = $booking->bookingRooms->firstWhere('id', (int) $roomLineIdInput);
            if (!$targetBookingRoom) {
                throw new \RuntimeException('BOOKING_ROOM_LINE_INVALID');
            }
        } elseif ($booking->bookingRooms->count() === 1) {
            $targetBookingRoom = $booking->bookingRooms->first();
        } else {
            throw new \RuntimeException('BOOKING_ROOM_LINE_REQUIRED');
        }

        $lockedLine = BookingRoom::query()
            ->with('room')
            ->whereKey((int) $targetBookingRoom->id)
            ->lockForUpdate()
            ->first();

        if (!$lockedLine) {
            throw new \RuntimeException('BOOKING_ROOM_LINE_MISSING');
        }

        return $lockedLine;
    }

    /**
     * @return array<int, array{booking_room_id:int|null, requested_room_id:int}>
     *
     * @throws \RuntimeException
     */
    private function normalizeRoomChangesPayload(array $payload): array
    {
        $roomChanges = $payload['room_changes'] ?? null;
        if (!is_array($roomChanges) || count($roomChanges) === 0) {
            $legacyRequestedRoomId = (int) ($payload['new_room_id'] ?? 0);
            $legacyBookingRoomId = $payload['booking_room_id'] ?? ($payload['original_booking_room_id'] ?? null);
            if ($legacyRequestedRoomId > 0 || $legacyBookingRoomId !== null) {
                $roomChanges = [[
                    'booking_room_id' => $legacyBookingRoomId,
                    'requested_room_id' => $legacyRequestedRoomId,
                ]];
            } else {
                $roomChanges = [];
            }
        }

        $normalized = [];
        foreach ($roomChanges as $change) {
            if (!is_array($change)) {
                continue;
            }

            $normalized[] = [
                'booking_room_id' => isset($change['booking_room_id']) && $change['booking_room_id'] !== ''
                    ? (int) $change['booking_room_id']
                    : null,
                'requested_room_id' => (int) ($change['requested_room_id'] ?? $change['new_room_id'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Rebooking>
     */
    private function resolveGroupedRowsForDecision(Rebooking $selectedRebooking)
    {
        $groupId = (int) ($selectedRebooking->rebooking_group_id ?: $selectedRebooking->id);

        $groupRows = Rebooking::query()
            ->with([
                'originalBooking.primaryGuest',
                'originalBooking.bookingRooms.room',
                'originalBookingRoom.room',
                'requestedRoom',
            ])
            ->where('original_booking_id', (int) $selectedRebooking->original_booking_id)
            ->where(function ($q) use ($groupId, $selectedRebooking) {
                $q->where('rebooking_group_id', $groupId)
                    ->orWhere('id', $groupId);

                if ((int) ($selectedRebooking->rebooking_group_id ?? 0) === 0) {
                    $q->orWhere('id', (int) $selectedRebooking->id);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($groupRows->isNotEmpty()) {
            return $groupRows;
        }

        return collect([$selectedRebooking]);
    }

    /**
     * @throws \RuntimeException
     */
    private function assertRequestedRoomAvailableForBooking(Booking $booking, int $requestedRoomId): void
    {
        $pendingExpiryCutoff = now()->subMinutes((int) config('bookings.pending_expiry_minutes', 30));
        $now = now();

        $hasOverlap = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->where('booking_rooms.room_id', $requestedRoomId)
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
            ->where('bookings.check_in', '<', $booking->check_out)
            ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$booking->check_in])
            ->exists();

        if ($hasOverlap) {
            throw new \RuntimeException('REQUESTED_ROOM_NOT_AVAILABLE');
        }

        $hasOtherActiveHold = DB::table('rebooking_room_holds')
            ->where('room_id', $requestedRoomId)
            ->where('booking_id', '!=', $booking->id)
            ->whereNull('released_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where('check_in', '<', $booking->check_out)
            ->where('check_out', '>', $booking->check_in)
            ->exists();

        if ($hasOtherActiveHold) {
            throw new \RuntimeException('REQUESTED_ROOM_NOT_AVAILABLE');
        }
    }

    private function assertRequestedRoomOperationalForBooking(Booking $booking, Room $room): void
    {
        $roomStatus = strtolower(trim((string) $room->status));
        $checkIn = Carbon::parse($booking->check_in)->startOfDay();

        if ($checkIn->isSameDay(now()->startOfDay())) {
            if ($roomStatus !== 'available') {
                throw new \RuntimeException('REQUESTED_ROOM_NOT_OPERATIONAL');
            }

            return;
        }

        if (in_array($roomStatus, ['maintenance', 'cleaning'], true)) {
            throw new \RuntimeException('REQUESTED_ROOM_NOT_OPERATIONAL');
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : null;
    }

    private function isOpenRequestUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'rbk_booking_open_unique')
            || str_contains($message, 'rebookings_open_request_unique');
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
