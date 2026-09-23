<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Helpers\NotificationHelper;
use App\Mail\BookingWorkflowDecisionMail;
use App\Models\Booking;
use App\Models\Cancellation;
use App\Models\CancellationApprovalRequest;
use App\Models\ManualGcashRefund;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CancellationApprovalService
{
    private const EPSILON = 0.00001;
    private const OPEN_REQUEST_STATUSES = [
        CancellationApprovalRequest::STATUS_PENDING_APPROVAL,
        CancellationApprovalRequest::STATUS_APPROVED,
        CancellationApprovalRequest::STATUS_REFUND_PENDING,
        CancellationApprovalRequest::STATUS_REFUNDED,
    ];

    public function __construct(private BookingCancellationService $bookingCancellationService)
    {
    }

    /**
     * @throws \RuntimeException
     */
    public function createRequest(
        string|int $bookingKey,
        array $payload,
        ?User $requester = null,
        ?string $actorLabel = null
    ): CancellationApprovalRequest
    {
        return DB::transaction(function () use ($bookingKey, $payload, $requester, $actorLabel) {
            $booking = $this->findBookingForUpdate($bookingKey);
            return $this->createRequestForLockedBooking($booking, $payload, $requester, $actorLabel);
        });
    }

    /**
     * Handles guest cancellation from an already locked booking row.
     * Returns [statusCode, payload] for direct controller responses.
     *
     * @throws \RuntimeException
     */
    public function handleGuestCancellationForLockedBooking(
        Booking $booking,
        string $cancelledReasonCode = 'guest_cancelled',
        ?string $reasonText = null,
        ?array $refundRecipientDetails = null
    ): array {
        if ($booking->booking_status === 'cancelled') {
            $booking->loadMissing('cancellation');
            $cancellation = $booking->cancellation;

            return [200, [
                'success' => true,
                'message' => 'Booking is already cancelled.',
                'data' => [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'booking_status' => $booking->booking_status,
                    'cancellation_state' => 'cancelled',
                    'refund_status' => $cancellation?->refund_status ?? 'none',
                    'refund_amount' => (float) ($cancellation?->refund_amount ?? 0),
                ],
            ]];
        }

        if (in_array($booking->booking_status, ['checked_in', 'checked_out', 'no_show'], true)) {
            throw new \RuntimeException('BOOKING_NOT_CANCELLABLE:' . $booking->booking_status);
        }

        $openRequest = $this->findOpenRequestForUpdate($booking->id);
        if ($openRequest) {
            return [202, [
                'success' => true,
                'message' => 'A cancellation request is already in progress for this booking.',
                'data' => [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'request_id' => $openRequest->id,
                    'request_status' => $openRequest->status,
                    'cancellation_state' => $openRequest->status,
                    'refund_amount' => (float) $openRequest->refund_amount,
                ],
            ]];
        }

        $reasonText = $this->nullableTrim($reasonText);
        if ($reasonText === null) {
            $reasonText = 'Cancelled by guest request.';
        }

        $isNonRefundable = $this->bookingCancellationService->isNonRefundableNow($booking);
        $remainingRefundable = $this->remainingRefundableAmount($booking);

        if (!$isNonRefundable && $remainingRefundable > self::EPSILON) {
            $recipient = [];
            $recipientService = app(CancellationRefundRecipientService::class);
            if ($refundRecipientDetails !== null && $recipientService->context($booking)['required']) {
                $recipient = $recipientService->confirmedDetails($refundRecipientDetails);
            }
            $request = $this->createRequestForLockedBooking(
                booking: $booking,
                payload: [
                    'reason' => $reasonText,
                    'refund_amount' => $remainingRefundable,
                    'refund_method' => 'original_payment_method',
                    'request_note' => 'Submitted by guest portal.',
                    ...$recipient,
                ],
                requester: null,
                actorLabel: 'Guest Portal'
            );

            return [202, [
                'success' => true,
                'message' => 'Cancellation request submitted. Admin approval is required before final cancellation.',
                'data' => [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'request_id' => $request->id,
                    'request_status' => $request->status,
                    'cancellation_state' => $request->status,
                    'refund_amount' => (float) $request->refund_amount,
                ],
            ]];
        }

        $request = $this->cancelLockedImmediately(
            booking: $booking,
            payload: [
                'reason' => $reasonText,
                'cancelled_reason_code' => $cancelledReasonCode,
                'refund_amount' => 0,
                'refund_status' => 'none',
                'request_note' => 'Submitted by guest portal.',
            ],
            actorUser: null,
            actorLabel: 'Guest Portal'
        );

        $booking->loadMissing('cancellation');
        $cancellation = $booking->cancellation;

        return [200, [
            'success' => true,
            'message' => 'Booking cancelled successfully.',
            'data' => [
                'booking_id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'booking_status' => $booking->booking_status,
                'cancellation_state' => 'cancelled',
                'request_id' => $request->id,
                'refund_status' => $cancellation?->refund_status ?? 'none',
                'refund_amount' => (float) ($cancellation?->refund_amount ?? 0),
            ],
        ]];
    }

    /**
     * Unified direct cancellation path for system/staff/legacy auto-cancel flows.
     * Must be used for every non-approval cancellation path.
     *
     * @throws \RuntimeException
     */
    public function cancelImmediately(
        string|int $bookingKey,
        array $payload,
        ?User $actorUser = null,
        ?string $actorLabel = null
    ): CancellationApprovalRequest {
        return DB::transaction(function () use ($bookingKey, $payload, $actorUser, $actorLabel) {
            $booking = $this->findBookingForUpdate($bookingKey);
            return $this->cancelLockedImmediately($booking, $payload, $actorUser, $actorLabel);
        });
    }

    /**
     * Same as cancelImmediately() but assumes caller already has booking lock.
     *
     * @throws \RuntimeException
     */
    public function cancelLockedImmediately(
        Booking $booking,
        array $payload,
        ?User $actorUser = null,
        ?string $actorLabel = null
    ): CancellationApprovalRequest {
        $reasonText = $this->nullableTrim($payload['reason'] ?? null) ?? 'Booking cancelled.';
        $reasonCode = $this->nullableTrim($payload['cancelled_reason_code'] ?? null) ?? 'system_cancelled';
        $refundAmount = max(0, round((float) ($payload['refund_amount'] ?? 0), 2));
        $refundStatus = $this->nullableTrim($payload['refund_status'] ?? null)
            ?? ($refundAmount > 0 ? 'pending' : 'none');
        $refundMethod = $refundAmount > 0
            ? ($this->nullableTrim($payload['refund_method'] ?? null) ?? 'manual_review')
            : null;
        $requestNote = $this->nullableTrim($payload['request_note'] ?? null);
        $decisionNote = $this->nullableTrim($payload['decision_note'] ?? null);
        $finalizeNote = $this->nullableTrim($payload['finalize_note'] ?? null);
        $cancellationFee = max(0, round((float) ($payload['cancellation_fee'] ?? 0), 2));

        if ($booking->booking_status === 'cancelled') {
            $existing = CancellationApprovalRequest::query()
                ->where('booking_id', $booking->id)
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing->fresh([
                    'booking.primaryGuest',
                    'booking.bookingRooms.room',
                    'requester',
                    'approver',
                    'finalizer',
                    'cancellation',
                ]);
            }

            $booking->loadMissing('cancellation');
            $fallback = CancellationApprovalRequest::create([
                'booking_id' => $booking->id,
                'reason' => $reasonText,
                'refund_amount' => $refundAmount,
                'refund_method' => $refundMethod,
                'request_note' => $requestNote,
                'requested_by' => $actorUser?->id,
                'status' => $refundAmount > 0
                    ? CancellationApprovalRequest::STATUS_REFUND_PENDING
                    : CancellationApprovalRequest::STATUS_CANCELLED,
                'decision_note' => $this->mergeNotes(null, $decisionNote, 'Auto-approval'),
                'approved_by' => $actorUser?->id,
                'approved_at' => now(),
                'refund_processed_at' => $refundStatus === 'refunded' ? now() : null,
                'finalized_by' => $actorUser?->id,
                'finalized_at' => now(),
                'cancellation_id' => $booking->cancellation?->id,
            ]);

            AuditHelper::log(
                actionActivity: 'Cancellation Record Backfilled',
                modulePage: 'Cancellation Module',
                modelType: 'CancellationApprovalRequest',
                modelId: $fallback->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: null,
                newValues: [
                    'status' => $fallback->status,
                    'booking_status' => $booking->booking_status,
                    'reason_code' => $reasonCode,
                ],
                action: 'created',
                actorUser: $actorUser,
                actorLabel: $actorLabel
            );

            return $fallback->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'requester',
                'approver',
                'finalizer',
                'cancellation',
            ]);
        }

        if (in_array($booking->booking_status, ['checked_in', 'checked_out', 'no_show'], true)) {
            throw new \RuntimeException('BOOKING_NOT_CANCELLABLE:' . $booking->booking_status);
        }

        if (($payload['require_unpaid'] ?? false) && $this->remainingRefundableAmount($booking) > self::EPSILON) {
            throw new \RuntimeException('SETTLED_PAYMENT_REQUIRES_REFUND');
        }

        if ($refundAmount > 0) {
            $remainingRefundable = $this->remainingRefundableAmount($booking);
            if ($refundAmount - $remainingRefundable > self::EPSILON) {
                throw new \RuntimeException('REFUND_EXCEEDS_PAID');
            }
        }

        $request = $this->findOpenRequestForUpdate($booking->id);

        if (!$request) {
            $request = $this->createRequestForLockedBooking(
                booking: $booking,
                payload: [
                    'reason' => $reasonText,
                    'refund_amount' => $refundAmount,
                    'refund_method' => $refundMethod,
                    'request_note' => $requestNote,
                ],
                requester: $actorUser,
                actorLabel: $actorLabel,
                skipOpenCheck: true
            );
        } else {
            if ($request->reason === null || trim((string) $request->reason) === '') {
                $request->reason = $reasonText;
            }
            if ($request->request_note === null && $requestNote !== null) {
                $request->request_note = $requestNote;
            }
            if ($request->requested_by === null && $actorUser?->id) {
                $request->requested_by = $actorUser->id;
            }
            if ($refundAmount > 0) {
                $request->refund_amount = max((float) $request->refund_amount, $refundAmount);
                if ($request->refund_method === null && $refundMethod !== null) {
                    $request->refund_method = $refundMethod;
                }
            }
        }

        $oldRequestStatus = $request->status;
        $request->status = $refundAmount > 0
            ? CancellationApprovalRequest::STATUS_REFUND_PENDING
            : CancellationApprovalRequest::STATUS_APPROVED;
        $request->approved_by = $actorUser?->id;
        $request->approved_at = now();
        $request->rejected_at = null;
        $request->decision_note = $this->mergeNotes($request->decision_note, $decisionNote, 'Auto-approval');
        $request->save();

        AuditHelper::log(
            actionActivity: 'Cancellation Request Auto-Approved',
            modulePage: 'Cancellation Module',
            modelType: 'CancellationApprovalRequest',
            modelId: $request->id,
            recordAffected: 'Booking ' . $booking->reference_number,
            oldValues: ['status' => $oldRequestStatus],
            newValues: [
                'status' => $request->status,
                'approved_by' => $actorUser?->name ?? $actorLabel ?? 'System',
                'refund_amount' => (float) $request->refund_amount,
            ],
            action: 'updated',
            actorUser: $actorUser,
            actorLabel: $actorLabel
        );

        if ($request->status === CancellationApprovalRequest::STATUS_REFUND_PENDING) {
            AuditHelper::log(
                actionActivity: 'Cancellation Refund Pending',
                modulePage: 'Cancellation Module',
                modelType: 'CancellationApprovalRequest',
                modelId: $request->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: null,
                newValues: [
                    'status' => $request->status,
                    'refund_amount' => (float) $request->refund_amount,
                    'refund_method' => $request->refund_method,
                ],
                action: 'updated',
                actorUser: $actorUser,
                actorLabel: $actorLabel
            );
        }

        $this->bookingCancellationService->cancelLockedBooking(
            booking: $booking,
            cancelledReasonCode: $reasonCode,
            reasonText: $reasonText,
            cancelledByUserId: $actorUser?->id,
            refundStatus: $refundStatus,
            refundAmount: $refundAmount,
            cancellationFee: $cancellationFee
        );

        $booking->load('cancellation');

        $oldStatusBeforeFinalize = $request->status;
        if ($refundAmount <= 0) {
            $request->status = CancellationApprovalRequest::STATUS_CANCELLED;
        } elseif ($refundStatus === 'refunded') {
            $request->status = CancellationApprovalRequest::STATUS_REFUNDED;
            $request->refund_processed_at = $request->refund_processed_at ?? now();
        } else {
            $request->status = CancellationApprovalRequest::STATUS_REFUND_PENDING;
        }

        $request->finalized_by = $actorUser?->id;
        $request->finalized_at = now();
        $request->cancellation_id = $booking->cancellation?->id;
        $request->decision_note = $this->mergeNotes($request->decision_note, $finalizeNote, 'Finalize');
        $request->save();

        AuditHelper::log(
            actionActivity: 'Cancellation Finalized',
            modulePage: 'Cancellation Module',
            modelType: 'CancellationApprovalRequest',
            modelId: $request->id,
            recordAffected: 'Booking ' . $booking->reference_number,
            oldValues: ['status' => $oldStatusBeforeFinalize],
            newValues: [
                'status' => $request->status,
                'booking_status' => $booking->booking_status,
                'refund_status' => $booking->cancellation?->refund_status,
                'finalized_by' => $actorUser?->name ?? $actorLabel ?? 'System',
            ],
            action: 'updated',
            actorUser: $actorUser,
            actorLabel: $actorLabel
        );

        return $request->fresh([
            'booking.primaryGuest',
            'booking.bookingRooms.room',
            'requester',
            'approver',
            'finalizer',
            'cancellation',
        ]);
    }

    /**
     * Blocks lifecycle transitions while a cancellation request is still open.
     *
     * @throws \RuntimeException
     */
    public function assertLifecycleUnlockedForLockedBooking(Booking $booking, string $actionCode = 'booking_lifecycle'): void
    {
        $openRequest = $this->findOpenRequestForUpdate($booking->id);
        if (!$openRequest) {
            return;
        }

        throw new \RuntimeException(
            'BOOKING_LIFECYCLE_LOCKED:' . $openRequest->id . ':' . $openRequest->status . ':' . $actionCode
        );
    }

    /**
     * @throws \RuntimeException
     */
    public function approve(int $requestId, User $admin, ?string $decisionNote = null): CancellationApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $decisionNote) {
            $request = CancellationApprovalRequest::query()
                ->with(['booking', 'requester'])
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (in_array($request->status, ['approved', 'refund_pending', 'refunded', 'cancelled'], true)
                && $request->booking?->booking_status === 'cancelled') {
                return $request->fresh(['booking.primaryGuest', 'booking.bookingRooms.room', 'cancellation']);
            }
            if (!in_array($request->status, ['pending_approval', 'approved', 'refund_pending', 'refunded'], true)) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $booking = Booking::query()->lockForUpdate()->findOrFail($request->booking_id);
            $this->assertBookingRequestable($booking);

            if ((float) $request->refund_amount > 0 && $request->refund_processed_at === null) {
                $remainingRefundable = $this->remainingRefundableAmount($booking);
                if ((float) $request->refund_amount - $remainingRefundable > self::EPSILON) {
                    throw new \RuntimeException('REFUND_EXCEEDS_PAID');
                }
            }

            $request->status = $request->refund_processed_at !== null ? CancellationApprovalRequest::STATUS_REFUNDED
                : ((float) $request->refund_amount > 0 ? CancellationApprovalRequest::STATUS_REFUND_PENDING : CancellationApprovalRequest::STATUS_CANCELLED);
            $request->approved_by ??= $admin->id;
            $request->approved_at ??= now();
            $request->decision_note = $this->nullableTrim($decisionNote) ?? $request->decision_note;
            $request->rejected_at = null;
            $this->completeApprovedCancellation($request, $booking, $admin);
            $request->save();

            AuditHelper::log(
                actionActivity: 'Cancellation Request Approved',
                modulePage: 'Cancellation Module',
                modelType: 'CancellationApprovalRequest',
                modelId: $request->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: ['status' => CancellationApprovalRequest::STATUS_PENDING_APPROVAL],
                newValues: [
                    'status' => $request->status,
                    'approved_by' => $admin->name,
                    'decision_note' => $request->decision_note,
                ],
                action: 'updated',
                actorUser: $admin
            );

            if ($request->status === CancellationApprovalRequest::STATUS_REFUND_PENDING) {
                AuditHelper::log(
                    actionActivity: 'Cancellation Refund Pending',
                    modulePage: 'Cancellation Module',
                    modelType: 'CancellationApprovalRequest',
                    modelId: $request->id,
                    recordAffected: 'Booking ' . $booking->reference_number,
                    oldValues: null,
                    newValues: [
                        'status' => $request->status,
                        'refund_amount' => (float) $request->refund_amount,
                        'refund_method' => $request->refund_method,
                    ],
                    action: 'updated',
                    actorUser: $admin
                );
            }

            $this->queueDecisionEmail(
                booking: $request->fresh(['booking.primaryGuest'])->booking,
                heading: 'Reservation Cancelled',
                status: $request->status,
                messageBody: $request->status === CancellationApprovalRequest::STATUS_REFUND_PENDING
                    ? 'Your reservation is cancelled. Your refund of PHP '.number_format((float) $request->refund_amount, 2).' is pending. We will notify you when it has been recorded as completed.'
                    : 'Your reservation is cancelled. '.($request->refund_processed_at ? 'Your refund has been recorded as completed.' : 'No refund is due.'),
                decisionNote: $request->decision_note,
            );

            try {
                NotificationHelper::cancellationReviewed([
                    'request_id' => $request->id,
                    'booking_id' => $booking->reference_number,
                    'guest_name' => $booking->primaryGuest?->name,
                    'reviewed_by' => $admin->name,
                    'decision_note' => $request->decision_note,
                ], true);
            } catch (\Throwable $e) {
                // Keep cancellation workflow non-blocking when notification write fails.
            }

            return $request->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'requester',
                'approver',
                'finalizer',
                'cancellation',
            ]);
        });
    }

    /**
     * @throws \RuntimeException
     */
    public function reject(int $requestId, User $admin, ?string $decisionNote = null): CancellationApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $decisionNote) {
            $request = CancellationApprovalRequest::query()
                ->with(['booking', 'requester'])
                ->lockForUpdate()
                ->findOrFail($requestId);

            if ($request->status !== CancellationApprovalRequest::STATUS_PENDING_APPROVAL) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $request->status = CancellationApprovalRequest::STATUS_REJECTED;
            $request->approved_by = $admin->id;
            $request->approved_at = now();
            $request->rejected_at = now();
            $request->decision_note = $this->nullableTrim($decisionNote);
            $request->save();

            $booking = Booking::query()->find($request->booking_id);

            AuditHelper::log(
                actionActivity: 'Cancellation Request Rejected',
                modulePage: 'Cancellation Module',
                modelType: 'CancellationApprovalRequest',
                modelId: $request->id,
                recordAffected: $booking ? ('Booking ' . $booking->reference_number) : ('Request #' . $request->id),
                oldValues: ['status' => CancellationApprovalRequest::STATUS_PENDING_APPROVAL],
                newValues: [
                    'status' => $request->status,
                    'rejected_by' => $admin->name,
                    'decision_note' => $request->decision_note,
                ],
                action: 'updated',
                actorUser: $admin
            );

            $this->queueDecisionEmail(
                booking: $request->fresh(['booking.primaryGuest'])->booking,
                heading: 'Cancellation Request Rejected',
                status: $request->status,
                messageBody: 'Your cancellation request was reviewed and could not be approved. Your booking remains active under its current status.',
                decisionNote: $request->decision_note,
            );

            try {
                NotificationHelper::cancellationReviewed([
                    'request_id' => $request->id,
                    'booking_id' => $booking?->reference_number ?? ('#' . $request->booking_id),
                    'guest_name' => $booking?->primaryGuest?->name,
                    'reviewed_by' => $admin->name,
                    'decision_note' => $request->decision_note,
                ], false);
            } catch (\Throwable $e) {
                // Keep cancellation workflow non-blocking when notification write fails.
            }

            return $request->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'requester',
                'approver',
                'finalizer',
                'cancellation',
            ]);
        });
    }

    /**
     * @throws \RuntimeException
     */
    public function ensureManualGcashRefundRequired(
        Booking $booking,
        Payment $payment,
        User $admin,
        string $reason
    ): CancellationApprovalRequest {
        if ($payment->provider !== 'manual_gcash' || (int) $payment->booking_id !== (int) $booking->id) {
            throw new \RuntimeException('PAYMENT_BOOKING_MISMATCH');
        }

        $remainingRefundable = $this->remainingRefundableAmount($booking);
        if ($remainingRefundable <= self::EPSILON) {
            throw new \RuntimeException('NO_REFUNDABLE_CAPTURE_FOUND');
        }

        $request = $this->findOpenRequestForUpdate($booking->id);
        if (! $request) {
            $request = CancellationApprovalRequest::create([
                'booking_id' => $booking->id,
                'reason' => $reason,
                'refund_amount' => $remainingRefundable,
                'refund_method' => 'manual_gcash',
                'request_note' => 'Created from a resolved manual GCash reconciliation exception.',
                'status' => CancellationApprovalRequest::STATUS_REFUND_PENDING,
                'decision_note' => $reason,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);
        } else {
            $request->fill([
                'reason' => $reason,
                'refund_amount' => max((float) $request->refund_amount, $remainingRefundable),
                'refund_method' => 'manual_gcash',
                'status' => CancellationApprovalRequest::STATUS_REFUND_PENDING,
                'decision_note' => $this->mergeNotes($request->decision_note, $reason, 'Refund decision'),
                'approved_by' => $admin->id,
                'approved_at' => $request->approved_at ?? now(),
                'rejected_at' => null,
            ])->save();
        }

        ManualGcashRefund::firstOrCreate(
            ['cancellation_approval_request_id' => $request->id],
            [
                'booking_id' => $booking->id,
                'source_payment_id' => $payment->id,
                'status' => ManualGcashRefund::STATUS_APPROVED,
                'approved_amount' => $request->refund_amount,
                'approved_by' => $admin->id,
                'approved_at' => $request->approved_at ?? now(),
            ]
        );

        $payment->update([
            'lifecycle_status' => Payment::LIFECYCLE_REFUND_REQUIRED,
            'lifecycle_message' => $reason,
        ]);

        AuditHelper::log(
            actionActivity: 'Manual GCash Refund Approved',
            modulePage: 'Payment Module',
            modelType: 'CancellationApprovalRequest',
            modelId: $request->id,
            recordAffected: 'Booking '.$booking->reference_number,
            oldValues: null,
            newValues: [
                'status' => $request->status,
                'refund_amount' => (float) $request->refund_amount,
                'source_payment_id' => $payment->id,
            ],
            action: 'created',
            actorUser: $admin
        );

        return $request->fresh(['manualGcashRefund', 'booking.primaryGuest']);
    }

    public function processRefund(
        int $requestId,
        User $admin,
        array|string|null $refundDetails = null
    ): CancellationApprovalRequest
    {
        $details = is_array($refundDetails)
            ? $refundDetails
            : ['refund_note' => $refundDetails];

        $result = DB::transaction(function () use ($requestId, $admin, $details) {
            $request = CancellationApprovalRequest::query()
                ->with(['booking.payments', 'booking.bookingRooms', 'manualGcashRefund'])
                ->lockForUpdate()
                ->findOrFail($requestId);

            if ($request->status !== CancellationApprovalRequest::STATUS_REFUND_PENDING) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $refundAmount = round((float) $request->refund_amount, 2);
            if ($refundAmount <= 0) {
                throw new \RuntimeException('REFUND_AMOUNT_INVALID');
            }

            if ($request->refund_processed_at !== null) {
                throw new \RuntimeException('ALREADY_REFUNDED');
            }

            $booking = Booking::query()
                ->with(['payments', 'bookingRooms'])
                ->lockForUpdate()
                ->findOrFail($request->booking_id);

            $remainingRefundable = $this->remainingRefundableAmount($booking);
            if ($refundAmount - $remainingRefundable > self::EPSILON) {
                throw new \RuntimeException('REFUND_EXCEEDS_PAID');
            }

            $manualSource = $booking->payments
                ->where('provider', 'manual_gcash')
                ->where('payment_type', '!=', 'refund')
                ->sortByDesc(fn (Payment $payment) => $payment->lifecycle_status === Payment::LIFECYCLE_REFUND_REQUIRED ? 1 : 0)
                ->first();
            $manualRefund = $manualSource !== null;
            $refundNote = $details['refund_note'] ?? null;
            $processedAt = now();
            $providerReference = null;
            $manualRefundRecord = null;

            if ($manualRefund) {
                if (! in_array($details['manual_transfer_confirmed'] ?? false, [true, 1, '1', 'true', 'yes', 'on'], true)) {
                    throw new \RuntimeException('MANUAL_TRANSFER_CONFIRMATION_REQUIRED');
                }

                $recipientName = $this->nullableTrim($details['recipient_name'] ?? null);
                $recipientAccount = $this->normalizeGcashAccount($details['recipient_account'] ?? null);
                $providerReference = $this->normalizeRefundReference($details['gcash_reference'] ?? null);
                $refundReason = $this->nullableTrim($details['refund_reason'] ?? null);
                $proof = is_array($details['proof'] ?? null) ? $details['proof'] : [];

                if ($recipientName === null) {
                    throw new \RuntimeException('REFUND_RECIPIENT_REQUIRED');
                }
                if ($recipientAccount === null) {
                    throw new \RuntimeException('REFUND_RECIPIENT_ACCOUNT_INVALID');
                }
                if ($providerReference === null) {
                    throw new \RuntimeException('REFUND_REFERENCE_INVALID');
                }
                if ($refundReason === null || mb_strlen($refundReason) < 10) {
                    throw new \RuntimeException('REFUND_REASON_REQUIRED');
                }
                foreach (['proof_disk', 'proof_path', 'proof_original_name', 'proof_mime_type', 'proof_size', 'proof_sha256'] as $proofKey) {
                    if (empty($proof[$proofKey])) {
                        throw new \RuntimeException('REFUND_PROOF_REQUIRED');
                    }
                }

                try {
                    $processedAt = Carbon::parse((string) ($details['processed_at'] ?? ''));
                } catch (\Throwable) {
                    throw new \RuntimeException('REFUND_PROCESSED_AT_INVALID');
                }
                if ($processedAt->isFuture() || ($manualSource->paid_at && $processedAt->lt($manualSource->paid_at))) {
                    throw new \RuntimeException('REFUND_PROCESSED_AT_INVALID');
                }

                $manualRefundRecord = ManualGcashRefund::query()
                    ->where('cancellation_approval_request_id', $request->id)
                    ->lockForUpdate()
                    ->first();
                if ($manualRefundRecord?->status === ManualGcashRefund::STATUS_COMPLETED) {
                    throw new \RuntimeException('ALREADY_REFUNDED');
                }
                if (ManualGcashRefund::query()
                    ->where('normalized_gcash_reference', $providerReference)
                    ->when($manualRefundRecord, fn ($query) => $query->where('id', '!=', $manualRefundRecord->id))
                    ->exists()) {
                    throw new \RuntimeException('REFUND_REFERENCE_DUPLICATE');
                }

                $manualRefundRecord ??= new ManualGcashRefund([
                    'cancellation_approval_request_id' => $request->id,
                    'booking_id' => $booking->id,
                    'source_payment_id' => $manualSource->id,
                    'status' => ManualGcashRefund::STATUS_APPROVED,
                    'approved_amount' => $refundAmount,
                    'approved_by' => $request->approved_by,
                    'approved_at' => $request->approved_at ?? now(),
                ]);
                $manualRefundRecord->fill([
                    'recipient_name' => $recipientName,
                    'recipient_account' => $recipientAccount,
                    'recipient_account_last_four' => substr($recipientAccount, -4),
                    'gcash_reference' => trim((string) $details['gcash_reference']),
                    'normalized_gcash_reference' => $providerReference,
                    'processed_at' => $processedAt,
                    'processed_by' => $admin->id,
                    'reason' => $refundReason,
                    ...$proof,
                ])->save();
                $refundNote = $refundReason;
            }

            $refundPayment = Payment::create([
                'booking_id' => $booking->id,
                'amount' => $refundAmount,
                'payment_type' => 'refund',
                'payment_method' => $manualRefund ? 'gcash' : ($request->refund_method ?: 'refund'),
                'payment_status' => 'refunded',
                'paid_at' => $processedAt,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'provider' => $manualRefund ? 'manual_gcash' : 'manual',
                'provider_reference' => $providerReference,
                'notes' => $this->buildRefundNote($request, $refundNote),
            ]);

            if ($manualRefundRecord) {
                $manualRefundRecord->update([
                    'refund_payment_id' => $refundPayment->id,
                    'status' => ManualGcashRefund::STATUS_COMPLETED,
                ]);
            }

            if (abs($refundAmount - $remainingRefundable) <= self::EPSILON) {
                $booking->payments()
                    ->where('lifecycle_status', Payment::LIFECYCLE_REFUND_REQUIRED)
                    ->update([
                        'lifecycle_status' => Payment::LIFECYCLE_REFUNDED,
                        'lifecycle_message' => null,
                        'lifecycle_updated_at' => now(),
                    ]);
            }

            $request->refund_processed_at = $processedAt;
            $request->status = CancellationApprovalRequest::STATUS_REFUNDED;
            // Complete older approved requests as part of recording their refund.
            // New requests are already cancelled at approval.
            if ($booking->booking_status !== 'cancelled' || $request->cancellation_id === null) {
                $this->completeApprovedCancellation($request, $booking, $admin);
            }
            $trimmedRefundNote = $this->nullableTrim($refundNote);
            if ($trimmedRefundNote !== null) {
                $request->decision_note = $request->decision_note
                    ? trim($request->decision_note . "\n\nRefund: " . $trimmedRefundNote)
                    : ('Refund: ' . $trimmedRefundNote);
            }
            $request->save();

            AuditHelper::log(
                actionActivity: 'Cancellation Refund Processed',
                modulePage: 'Cancellation Module',
                modelType: 'CancellationApprovalRequest',
                modelId: $request->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: [
                    'request_status' => CancellationApprovalRequest::STATUS_REFUND_PENDING,
                    'refund_processed_at' => null,
                ],
                newValues: [
                    'request_status' => $request->status,
                    'refund_amount' => $refundAmount,
                    'refund_processed_at' => $request->refund_processed_at?->toDateTimeString(),
                    'processed_by' => $admin->name,
                    'manual_gcash_evidence_recorded' => $manualRefund,
                ],
                action: 'updated',
                actorUser: $admin
            );

            if ($request->cancellation_id) {
                $cancellation = $request->cancellation()->lockForUpdate()->first();
                if ($cancellation) {
                    $oldCancellationValues = [
                        'refund_status' => $cancellation->refund_status,
                        'refund_amount' => (float) $cancellation->refund_amount,
                        'refunded_at' => optional($cancellation->refunded_at)->toDateTimeString(),
                    ];

                    $cancellation->refund_status = 'refunded';
                    $cancellation->refund_amount = max((float) $cancellation->refund_amount, $refundAmount);
                    $cancellation->refunded_at = now();
                    $cancellation->staff_note = $this->mergeNotes(
                        $cancellation->staff_note,
                        $trimmedRefundNote,
                        'Refund'
                    );
                    $cancellation->save();

                    AuditHelper::log(
                        actionActivity: 'Cancellation Refund Completed',
                        modulePage: 'Cancellation Module',
                        modelType: 'Cancellation',
                        modelId: $cancellation->id,
                        recordAffected: 'Booking ' . $booking->reference_number,
                        oldValues: $oldCancellationValues,
                        newValues: [
                            'refund_status' => $cancellation->refund_status,
                            'refund_amount' => (float) $cancellation->refund_amount,
                            'refunded_at' => $cancellation->refunded_at?->toDateTimeString(),
                        ],
                        action: 'updated',
                        actorUser: $admin
                    );
                }
            }

            return [
                'request' => $request->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'requester',
                'approver',
                'finalizer',
                'cancellation',
                    'manualGcashRefund.processor',
                ]),
                'manual_refund' => $manualRefund,
                'refund_reference' => $manualRefundRecord?->gcash_reference,
                'refund_amount' => $refundAmount,
            ];
        });

        if ($result['manual_refund']) {
            $this->queueDecisionEmail(
                booking: $result['request']->booking,
                heading: 'GCash Refund Completed',
                status: CancellationApprovalRequest::STATUS_REFUNDED,
                messageBody: 'Your GCash refund of ₱'.number_format($result['refund_amount'], 2).' has been recorded as completed. Reference: '.$result['refund_reference'].'.',
                decisionNote: $result['request']->manualGcashRefund?->reason,
            );
        }

        return $result['request'];
    }

    /**
     * @throws \RuntimeException
     */
    public function finalize(int $requestId, User $admin, ?string $finalizeNote = null): CancellationApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $finalizeNote) {
            $request = CancellationApprovalRequest::query()
                ->with(['booking.payments', 'booking.bookingRooms', 'booking.cancellation'])
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (!in_array($request->status, [
                CancellationApprovalRequest::STATUS_APPROVED,
                CancellationApprovalRequest::STATUS_REFUNDED,
            ], true)) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            if ($request->finalized_at !== null) {
                throw new \RuntimeException('ALREADY_FINALIZED');
            }

            $booking = Booking::query()
                ->with(['payments', 'bookingRooms'])
                ->lockForUpdate()
                ->findOrFail($request->booking_id);

            if ($booking->booking_status === 'cancelled') {
                throw new \RuntimeException('BOOKING_ALREADY_CANCELLED');
            }

            $refundAmount = round((float) $request->refund_amount, 2);
            if ($refundAmount > 0) {
                if ($request->refund_processed_at === null) {
                    throw new \RuntimeException('REFUND_NOT_PROCESSED');
                }
            }

            $this->bookingCancellationService->cancelLockedBooking(
                booking: $booking,
                cancelledReasonCode: 'admin_approved_cancellation',
                reasonText: (string) $request->reason,
                cancelledByUserId: $admin->id,
                refundStatus: $refundAmount > 0 ? 'refunded' : 'none',
                refundAmount: $refundAmount,
                cancellationFee: 0.0
            );

            $booking->load('cancellation');

            $request->status = CancellationApprovalRequest::STATUS_CANCELLED;
            $request->finalized_by = $admin->id;
            $request->finalized_at = now();
            $request->cancellation_id = $booking->cancellation?->id;

            $trimmedFinalizeNote = $this->nullableTrim($finalizeNote);
            if ($trimmedFinalizeNote !== null) {
                $request->decision_note = $request->decision_note
                    ? trim($request->decision_note . "\n\nFinalize: " . $trimmedFinalizeNote)
                    : ('Finalize: ' . $trimmedFinalizeNote);
            }
            $request->save();

            AuditHelper::log(
                actionActivity: 'Cancellation Finalized',
                modulePage: 'Cancellation Module',
                modelType: 'CancellationApprovalRequest',
                modelId: $request->id,
                recordAffected: 'Booking ' . $booking->reference_number,
                oldValues: [
                    'booking_status' => $booking->getOriginal('booking_status'),
                    'request_status' => $request->getOriginal('status'),
                ],
                newValues: [
                    'booking_status' => $booking->booking_status,
                    'request_status' => $request->status,
                    'refund_amount' => $refundAmount,
                    'refund_processed_at' => $request->refund_processed_at?->toDateTimeString(),
                    'finalized_by' => $admin->name,
                ],
                action: 'updated',
                actorUser: $admin
            );

            return $request->fresh([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'requester',
                'approver',
                'finalizer',
                'cancellation',
            ]);
        });
    }

    private function createRequestForLockedBooking(
        Booking $booking,
        array $payload,
        ?User $requester = null,
        ?string $actorLabel = null,
        bool $skipOpenCheck = false
    ): CancellationApprovalRequest {
        $this->assertBookingRequestable($booking);
        if (!$skipOpenCheck) {
            $this->assertNoOpenRequest($booking->id);
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new \RuntimeException('REASON_REQUIRED');
        }

        $refundAmount = round((float) ($payload['refund_amount'] ?? 0), 2);
        $refundMethod = $refundAmount > 0
            ? trim((string) ($payload['refund_method'] ?? ''))
            : null;

        if ($refundAmount > 0) {
            $remainingRefundable = $this->remainingRefundableAmount($booking);
            if ($refundAmount - $remainingRefundable > self::EPSILON) {
                throw new \RuntimeException('REFUND_EXCEEDS_PAID');
            }
            if ($refundMethod === '') {
                throw new \RuntimeException('REFUND_METHOD_REQUIRED');
            }
        }

        try {
            $request = CancellationApprovalRequest::create([
                'booking_id' => $booking->id,
                'reason' => $reason,
                'refund_amount' => max(0, $refundAmount),
                'refund_method' => $refundMethod ?: null,
                'request_note' => $this->nullableTrim($payload['request_note'] ?? null),
                'requested_by' => $requester?->id,
                'refund_recipient_name' => $payload['refund_recipient_name'] ?? null,
                'refund_recipient_account' => $payload['refund_recipient_account'] ?? null,
                'refund_recipient_confirmed_at' => $payload['refund_recipient_confirmed_at'] ?? null,
                'status' => CancellationApprovalRequest::STATUS_PENDING_APPROVAL,
            ]);
        } catch (QueryException $e) {
            if ($this->isOpenRequestUniqueViolation($e)) {
                throw new \RuntimeException('OPEN_REQUEST_EXISTS');
            }

            throw $e;
        }

        AuditHelper::log(
            actionActivity: 'Cancellation Request Created',
            modulePage: 'Cancellation Module',
            modelType: 'CancellationApprovalRequest',
            modelId: $request->id,
            recordAffected: 'Booking ' . $booking->reference_number,
            oldValues: null,
            newValues: [
                'booking_id' => $booking->id,
                'booking_status' => $booking->booking_status,
                'refund_amount' => (float) $request->refund_amount,
                'refund_method' => $request->refund_method,
                'status' => $request->status,
            ],
            action: 'created',
            actorUser: $requester,
            actorLabel: $actorLabel
        );

        try {
            NotificationHelper::cancellationRequested([
                'request_id' => $request->id,
                'booking_id' => $booking->reference_number,
                'guest_name' => $booking->primaryGuest?->name ?? 'Guest',
                'room' => $booking->bookingRooms->pluck('room.room_number')->filter()->join(', '),
                'check_in' => optional($booking->check_in)->format('Y-m-d'),
                'check_out' => optional($booking->check_out)->format('Y-m-d'),
                'reason' => $request->reason,
                'refund_amount' => (float) $request->refund_amount,
            ]);
        } catch (\Throwable $e) {
            // Keep cancellation workflow non-blocking when notification write fails.
        }

        return $request->fresh([
            'booking.primaryGuest',
            'booking.bookingRooms.room',
            'requester',
            'approver',
            'finalizer',
            'cancellation',
        ]);
    }

    private function findBookingForUpdate(string|int $bookingKey): Booking
    {
        $query = Booking::query()->with(['payments', 'bookingRooms.room'])->lockForUpdate();

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
        if (in_array($booking->booking_status, ['checked_in', 'cancelled', 'checked_out', 'no_show'], true)) {
            throw new \RuntimeException('BOOKING_NOT_REQUESTABLE');
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function assertNoOpenRequest(int $bookingId): void
    {
        $hasOpen = $this->openRequestQuery($bookingId)
            ->lockForUpdate()
            ->exists();

        if ($hasOpen) {
            throw new \RuntimeException('OPEN_REQUEST_EXISTS');
        }
    }

    private function findOpenRequestForUpdate(int $bookingId): ?CancellationApprovalRequest
    {
        return $this->openRequestQuery($bookingId)
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function openRequestQuery(int $bookingId)
    {
        return CancellationApprovalRequest::query()
            ->where('booking_id', $bookingId)
            ->whereIn('status', self::OPEN_REQUEST_STATUSES)
            ->whereNull('finalized_at');
    }

    private function remainingRefundableAmount(Booking $booking): float
    {
        $totalPaid = (float) $booking->payments()
            ->where('payment_status', 'completed')
            ->where('payment_type', '!=', 'refund')
            ->lockForUpdate()
            ->sum('amount');

        $alreadyRefunded = (float) $booking->payments()
            ->where('payment_type', 'refund')
            ->lockForUpdate()
            ->sum('amount');

        return max(0, round($totalPaid - $alreadyRefunded, 2));
    }

    private function nullableTrim(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : null;
    }

    private function mergeNotes(?string $existing, ?string $newNote, string $prefix): ?string
    {
        $trimmed = $this->nullableTrim($newNote);
        if ($trimmed === null) {
            return $existing;
        }

        $line = $prefix . ': ' . $trimmed;
        return $existing ? trim($existing . "\n\n" . $line) : $line;
    }

    private function isOpenRequestUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'car_booking_open_unique')
            || str_contains($message, 'car_open_request_unique');
    }

    private function buildRefundNote(CancellationApprovalRequest $request, ?string $finalizeNote): string
    {
        $parts = ['Refund processed from approved cancellation request #' . $request->id . '.'];
        if ($request->request_note) {
            $parts[] = 'Request note: ' . $request->request_note;
        }
        $trimmedFinalizeNote = $this->nullableTrim($finalizeNote);
        if ($trimmedFinalizeNote) {
            $parts[] = 'Finalize note: ' . $trimmedFinalizeNote;
        }

        return implode(' ', $parts);
    }

    private function normalizeGcashAccount(mixed $account): ?string
    {
        $digits = preg_replace('/\D/', '', trim((string) $account)) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : null;
    }

    private function normalizeRefundReference(mixed $reference): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim((string) $reference)) ?? '');

        return strlen($normalized) >= 6 ? $normalized : null;
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

        DB::afterCommit(function () use ($guestEmail, $booking, $heading, $status, $messageBody, $decisionNote) {
            try {
                Mail::to($guestEmail)->queue(new BookingWorkflowDecisionMail(
                    booking: $booking, heading: $heading, status: $status,
                    messageBody: $messageBody, decisionNote: $decisionNote,
                ));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Cancellation decision email could not be queued', [
                    'booking_id' => $booking->id, 'status' => $status, 'exception' => get_class($e),
                ]);
            }
        });
    }

    private function completeApprovedCancellation(CancellationApprovalRequest $request, Booking $booking, User $admin): void
    {
        $this->bookingCancellationService->cancelLockedBooking(
            booking: $booking, cancelledReasonCode: 'admin_approved_cancellation',
            reasonText: (string) $request->reason, cancelledByUserId: $admin->id,
            refundStatus: $request->refund_processed_at ? 'refunded' : ((float) $request->refund_amount > 0 ? 'pending' : 'none'),
            refundAmount: (float) $request->refund_amount,
        );
        $booking->load('cancellation');
        $request->cancellation_id = $booking->cancellation?->id;
        $request->finalized_by ??= $admin->id;
        $request->finalized_at ??= now();
    }
}
