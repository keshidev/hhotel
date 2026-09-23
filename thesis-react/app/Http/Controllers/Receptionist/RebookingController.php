<?php

namespace App\Http\Controllers\Receptionist;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\Rebooking;
use App\Models\RebookingRefund;
use App\Services\RebookingAdjustmentService;
use App\Services\RebookingRequestService;
use App\Services\RoomPricingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RebookingController extends Controller
{
    public function __construct(
        private RebookingRequestService $rebookingRequestService,
        private RebookingAdjustmentService $rebookingAdjustmentService,
        private RoomPricingService $roomPricingService,
    ) {
    }

    private function rebookingRelations(): array
    {
        return [
            'originalBooking.primaryGuest',
            'originalBooking.bookingRooms.room',
            'newBooking.primaryGuest',
            'newBooking.bookingRooms.room',
            'originalBookingRoom.room',
            'originalRoom',
            'requestedRoom',
            'adjustmentPayment.manualGcashSubmissions',
            'roomHold',
            'refund.processor:id,name',
        ];
    }

    /**
     * Get all rebookings with filters.
     */
    public function index(Request $request)
    {
        $query = Rebooking::with($this->rebookingRelations())
            ->where('is_group_leader', true);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('originalBooking', function ($bookingQuery) use ($search) {
                    $bookingQuery->where('reference_number', 'like', "%{$search}%")
                        ->orWhereHas('primaryGuest', function ($guestQuery) use ($search) {
                            $guestQuery->where('name', 'like', "%{$search}%");
                        });
                });
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $status = strtolower((string) $request->status);
            if ($status === 'confirmed') {
                $status = 'approved';
            }
            $query->where('status', $status);
        }
        if ($request->boolean('open')) {
            $query->whereIn('status', Rebooking::OPEN_STATUSES)->whereNull('finalized_at');
        }

        $rebookings = $query->latest()->paginate((int) $request->input('per_page', 15));
        $leaderRows = $rebookings->getCollection();
        $groupRowsMap = $this->loadGroupedRowsForLeaders($leaderRows);

        return response()->json([
            'rebookings' => $leaderRows
                ->map(function (Rebooking $leader) use ($groupRowsMap) {
                    $groupKey = (int) ($leader->rebooking_group_id ?: $leader->id);
                    $groupRows = $groupRowsMap->get($groupKey, collect([$leader]));
                    return $this->transformRebookingGroup($groupRows);
                })
                ->values(),
            'total' => $rebookings->total(),
            'current_page' => $rebookings->currentPage(),
            'last_page' => $rebookings->lastPage(),
            'per_page' => $rebookings->perPage(),
        ]);
    }

    /**
     * Get single rebooking details.
     */
    public function show($id)
    {
        $rebooking = Rebooking::with($this->rebookingRelations())
            ->findOrFail($this->parseRebookingId($id));

        $groupRows = $this->loadGroupedRowsForLeader($rebooking);
        return response()->json($this->transformRebookingGroup($groupRows));
    }

    /**
     * Approve pending rebooking request.
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $note = trim((string) $request->input('note', ''));
        $parsedId = $this->parseRebookingId($id);

        try {
            $rebooking = $this->rebookingRequestService->approve(
                requestId: $parsedId,
                actor: $request->user(),
                note: $note !== '' ? $note : null
            );
        } catch (\RuntimeException $e) {
            return $this->handleDomainException(
                code: $e->getMessage(),
                requestId: $parsedId,
                action: 'Rebooking Approve Attempt Blocked',
                actor: $request->user()
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Room change request approved and room allocation updated successfully.',
            'rebooking' => $this->transformRebookingGroup($this->loadGroupedRowsForLeader($rebooking)),
        ]);
    }

    /**
     * Reject pending rebooking request.
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $parsedId = $this->parseRebookingId($id);
        $note = trim((string) $request->input('note', ''));

        try {
            $rebooking = $this->rebookingRequestService->reject(
                requestId: $parsedId,
                actor: $request->user(),
                note: $note !== '' ? $note : null
            );
        } catch (\RuntimeException $e) {
            return $this->handleDomainException(
                code: $e->getMessage(),
                requestId: $parsedId,
                action: 'Rebooking Reject Attempt Blocked',
                actor: $request->user()
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Room change request rejected.',
            'rebooking' => $this->transformRebookingGroup($this->loadGroupedRowsForLeader($rebooking)),
        ]);
    }

    public function requestAdditionalPayment(Request $request, $id)
    {
        try {
            $result = $this->rebookingAdjustmentService->requestAdditionalPayment(
                $this->parseRebookingId($id),
                $request->user()
            );
        } catch (\RuntimeException $e) {
            return $this->handleDomainException($e->getMessage(), $this->parseRebookingId($id), 'Rebooking Payment Request Blocked', $request->user());
        }

        $rebooking = Rebooking::with($this->rebookingRelations())->findOrFail($result['rebooking']->id);
        return response()->json([
            'success' => true,
            'message' => 'Additional payment request sent to the guest. The requested room is now held during payment verification.',
            'rebooking' => $this->transformRebookingGroup($this->loadGroupedRowsForLeader($rebooking)),
        ]);
    }

    public function sendForRefundReview(Request $request, $id)
    {
        try {
            $rebooking = $this->rebookingAdjustmentService->sendForRefundReview(
                $this->parseRebookingId($id),
                $request->user()
            );
        } catch (\RuntimeException $e) {
            return $this->handleDomainException($e->getMessage(), $this->parseRebookingId($id), 'Rebooking Refund Review Blocked', $request->user());
        }

        return response()->json([
            'success' => true,
            'message' => 'Request sent to the administrator for refund review.',
            'rebooking' => $this->transformRebookingGroup($this->loadGroupedRowsForLeader($rebooking)),
        ]);
    }

    public function processRefund(Request $request, $id)
    {
        $validated = $request->validate([
            'recipient_name' => 'required|string|max:120',
            'recipient_account' => 'required|string|max:32',
            'gcash_reference' => 'required|string|max:80',
            'processed_at' => 'required|date|before_or_equal:now',
            'refund_reason' => 'required|string|min:10|max:1000',
            'manual_transfer_confirmed' => 'accepted',
            'proof' => 'required|file|max:5120',
        ]);
        $proof = $this->storeRefundProof($request->file('proof'));
        $validated['proof'] = $proof;

        try {
            $rebooking = $this->rebookingAdjustmentService->processRefundAndFinalize(
                $this->parseRebookingId($id),
                $request->user(),
                $validated
            );
        } catch (\Throwable $e) {
            // A completed transfer is recorded before approval is attempted. Its
            // evidence must survive an approval failure, including unexpected errors.
            $recordedRefund = RebookingRefund::where('proof_disk', $proof['proof_disk'])
                ->where('proof_path', $proof['proof_path'])->first();
            if ($recordedRefund) {
                Log::error('Rebooking approval failed after refund evidence was recorded', [
                    'rebooking_id' => $recordedRefund->rebooking_id,
                    'refund_id' => $recordedRefund->id,
                    'exception' => $e,
                ]);

                $rebooking = Rebooking::findOrFail($recordedRefund->rebooking_id);
                return response()->json([
                    'success' => true,
                    'finalized' => false,
                    'message' => 'Refund and proof saved, but room change approval could not finish. Resolve the approval issue, then use Approve Room Change to retry. Do not send or record another refund.',
                    'rebooking' => $this->transformRebookingGroup($this->loadGroupedRowsForLeader($rebooking)),
                ], 202);
            }

            Storage::disk($proof['proof_disk'])->delete($proof['proof_path']);
            if ($e instanceof \RuntimeException) {
                return $this->handleDomainException($e->getMessage(), $this->parseRebookingId($id), 'Rebooking Refund Attempt Blocked', $request->user());
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Refund evidence recorded and room change finalized.',
            'finalized' => true,
            'rebooking' => $this->transformRebookingGroup($this->loadGroupedRowsForLeader($rebooking)),
        ]);
    }

    public function refundProof($id): StreamedResponse
    {
        $refund = RebookingRefund::where('rebooking_id', $this->parseRebookingId($id))->firstOrFail();
        if ($refund->proof_deleted_at) abort(410, 'Refund proof has reached the end of its retention period.');
        if (! Storage::disk($refund->proof_disk)->exists($refund->proof_path)) abort(404);
        $contents = Storage::disk($refund->proof_disk)->get($refund->proof_path);
        if (! hash_equals($refund->proof_sha256, hash('sha256', $contents))) {
            Log::critical('Rebooking refund proof integrity check failed', ['refund_id' => $refund->id]);
            abort(409, 'Proof integrity verification failed.');
        }
        AuditHelper::log(
            actionActivity: 'Rebooking Refund Proof Viewed',
            modulePage: 'Rebooking Module',
            modelType: 'RebookingRefund',
            modelId: $refund->id,
            recordAffected: 'Rebooking refund #'.$refund->id,
            oldValues: null,
            newValues: ['viewer' => auth()->user()?->name],
            action: 'viewed'
        );
        return response()->streamDownload(fn () => print($contents), $refund->proof_original_name, [
            'Content-Type' => $refund->proof_mime_type,
            'Content-Length' => (string) $refund->proof_size,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }

    private function handleDomainException(string $code, int $requestId, string $action, ?\App\Models\User $actor)
    {
        $message = match (true) {
            $code === 'INVALID_STATUS' => 'Only pending room change requests can be processed.',
            $code === 'BOOKING_NOT_FOUND' => 'Original booking no longer exists.',
            $code === 'BOOKING_NOT_CONFIRMED' => 'Room changes can only be approved for confirmed bookings.',
            $code === 'BOOKING_PAYMENT_NOT_SETTLED' => 'This booking still has a pending or unverified payment. Finish payment review before approving a room change.',
            str_starts_with($code, 'BOOKING_LIFECYCLE_LOCKED:') => 'Room change action is blocked while a cancellation request is in progress for this booking.',
            $code === 'BOOKING_ROOM_LINE_MISSING' => 'The original room allocation line is missing. Please reject and ask guest to submit a new request.',
            $code === 'LEGACY_MULTI_ROOM_REBOOKING_UNSAFE' => 'Cannot safely approve this legacy rebooking for a multi-room booking. Please reject and request a new line-item rebooking.',
            $code === 'REQUESTED_ROOM_NOT_FOUND' => 'Requested room no longer exists.',
            $code === 'REQUESTED_ROOM_ALREADY_ASSIGNED' => 'Requested room is already the active room for this allocation line.',
            $code === 'REQUESTED_ROOM_DUPLICATE_IN_BOOKING' => 'Requested room is already assigned to another room allocation in this booking.',
            $code === 'REQUESTED_ROOM_DUPLICATE_IN_REQUEST' => 'Each selected booking room must have a different requested room.',
            $code === 'REQUESTED_ROOM_NOT_OPERATIONAL' => 'Requested room is under maintenance, cleaning, or otherwise not ready for this stay.',
            $code === 'REQUESTED_ROOM_NOT_AVAILABLE' => 'Requested room is no longer available for this stay period.',
            str_starts_with($code, 'ADDITIONAL_PAYMENT_REQUIRED:') => 'Approval blocked: the new room requires an additional verified GCash downpayment of ₱' . number_format((float) explode(':', $code, 2)[1], 2) . '. Keep this request pending until the additional payment is handled through a supported process.',
            str_starts_with($code, 'REFUND_REVIEW_REQUIRED:') => 'Approval blocked: this change would create an overpayment of ₱' . number_format((float) explode(':', $code, 2)[1], 2) . '. Complete a refund review before approval.',
            $code === 'ADDITIONAL_PAYMENT_NOT_REQUIRED' => 'This request no longer needs an additional payment. Refresh and review the updated financial status.',
            $code === 'REFUND_NOT_REQUIRED' => 'This request no longer creates a refund. Refresh and review the updated financial status.',
            $code === 'VERIFIED_ADJUSTMENT_PAYMENT_EXISTS' => 'This request has a verified additional payment and cannot be rejected without administrator review.',
            $code === 'REFUND_RECIPIENT_REQUIRED' => 'GCash refund recipient name is required.',
            $code === 'REFUND_RECIPIENT_ACCOUNT_INVALID' => 'Enter a valid Philippine GCash mobile number.',
            $code === 'REFUND_REFERENCE_INVALID' => 'Enter a valid GCash refund reference.',
            $code === 'REFUND_REFERENCE_DUPLICATE' => 'This GCash refund reference has already been recorded.',
            $code === 'REFUND_REASON_REQUIRED' => 'Enter a clear refund reason of at least 10 characters.',
            $code === 'REFUND_PROOF_REQUIRED' => 'Upload the official GCash refund proof.',
            $code === 'REFUND_PROCESSED_AT_INVALID' => 'Enter a valid refund date and time that is not in the future.',
            $code === 'MANUAL_TRANSFER_CONFIRMATION_REQUIRED' => 'Confirm that the refund was already sent from the official hotel GCash account.',
            $code === 'REFUND_AMOUNT_INVALID' => 'The refund amount is invalid.',
            $code === 'PAYMENT_SERVICE_UNAVAILABLE' => 'Manual GCash is currently unavailable or incomplete. Restore Payment Operations before requesting additional payment.',
            $code === 'PAYMENT_REQUEST_ALREADY_ACTIVE' => 'An active payment request or proof review already exists for this room change.',
            $code === 'ADJUSTMENT_PAYMENT_UNDER_REVIEW' => 'This request cannot be rejected while the guest payment proof is under verification. Finish the payment review first.',
            default => 'Unable to process room change request.',
        };

        $status = match (true) {
            $code === 'BOOKING_NOT_FOUND',
            $code === 'REQUESTED_ROOM_NOT_FOUND' => 404,
            $code === 'INVALID_STATUS',
            $code === 'BOOKING_NOT_CONFIRMED',
            $code === 'BOOKING_PAYMENT_NOT_SETTLED',
            str_starts_with($code, 'BOOKING_LIFECYCLE_LOCKED:'),
            $code === 'BOOKING_ROOM_LINE_MISSING',
            $code === 'LEGACY_MULTI_ROOM_REBOOKING_UNSAFE',
            $code === 'REQUESTED_ROOM_ALREADY_ASSIGNED',
            $code === 'REQUESTED_ROOM_DUPLICATE_IN_BOOKING',
            $code === 'REQUESTED_ROOM_DUPLICATE_IN_REQUEST',
            $code === 'REQUESTED_ROOM_NOT_OPERATIONAL',
            $code === 'REQUESTED_ROOM_NOT_AVAILABLE',
            str_starts_with($code, 'ADDITIONAL_PAYMENT_REQUIRED:'),
            str_starts_with($code, 'REFUND_REVIEW_REQUIRED:') => 409,
            $code === 'ADDITIONAL_PAYMENT_NOT_REQUIRED',
            $code === 'REFUND_NOT_REQUIRED',
            $code === 'VERIFIED_ADJUSTMENT_PAYMENT_EXISTS',
            $code === 'REFUND_REFERENCE_DUPLICATE' => 409,
            $code === 'PAYMENT_SERVICE_UNAVAILABLE' => 503,
            $code === 'PAYMENT_REQUEST_ALREADY_ACTIVE',
            $code === 'ADJUSTMENT_PAYMENT_UNDER_REVIEW' => 409,
            default => 422,
        };

        AuditHelper::log(
            actionActivity: $action,
            modulePage: 'Rebooking Module',
            modelType: 'Rebooking',
            modelId: $requestId,
            recordAffected: 'Rebooking #' . $requestId,
            oldValues: null,
            newValues: [
                'result' => 'blocked',
                'reason_code' => $code,
                'message' => $message,
            ],
            action: 'blocked',
            actorUser: $actor
        );

        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    private function parseRebookingId($id): int
    {
        if (is_numeric($id)) {
            return (int) $id;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $id);
    }

    private function transformRebookingGroup($groupRows): array
    {
        $rows = collect($groupRows)->values();
        $leader = $rows->firstWhere('is_group_leader', true) ?? $rows->first();

        $originalBooking = $leader?->originalBooking;
        $newBooking = $leader?->newBooking;

        $roomChanges = $rows->map(function (Rebooking $rebooking) use ($originalBooking, $newBooking) {
            // Prefer explicit line-item context; fallback to first room for legacy rows.
            $originalRoomLine = $rebooking->originalBookingRoom ?? $originalBooking?->bookingRooms->first();
            $historicalOldRoom = $rebooking->originalRoom ?? $originalRoomLine?->room;

            $requestedRoomLabel = $rebooking->requestedRoom
                ? ($rebooking->requestedRoom->room_type . ' ' . $rebooking->requestedRoom->room_number)
                : null;

            $newRoom = $newBooking?->bookingRooms->first();
            $newRoomLabel = ($newRoom && $newRoom->room)
                ? ($newRoom->room->room_type . ' ' . $newRoom->room->room_number)
                : ($requestedRoomLabel ? $requestedRoomLabel . ' (Requested)' : 'TBD');

            $oldRoomLabel = $historicalOldRoom
                ? ($historicalOldRoom->room_type . ' ' . $historicalOldRoom->room_number)
                : 'N/A';

            $nights = $newRoom?->nights
                ?? $originalRoomLine?->nights
                ?? ($originalBooking?->nights ?? 0);

            $priceDiffAmount = 0.0;
            if ($newBooking && $originalBooking) {
                $priceDiffAmount = (float) $newBooking->total_amount - (float) $originalBooking->total_amount;
            } elseif ($originalBooking && $originalRoomLine && $rebooking->requestedRoom) {
                $oldRoomRate = (float) $originalRoomLine->price_per_night;
                $newRoomRate = $this->roomPricingService->resolveNightlyRateForRoom($rebooking->requestedRoom);
                $effectiveNights = max(1, (int) ($originalRoomLine->nights ?? $originalBooking->nights ?? 1));
                $priceDiffAmount = ($newRoomRate - $oldRoomRate) * $effectiveNights;
            }

            return [
                'rebookingId' => 'RBK' . str_pad((string) $rebooking->id, 3, '0', STR_PAD_LEFT),
                'originalBookingRoomId' => $rebooking->original_booking_room_id ? (int) $rebooking->original_booking_room_id : null,
                'requestedRoomId' => $rebooking->requested_room_id ? (int) $rebooking->requested_room_id : null,
                'oldRoom' => $oldRoomLabel,
                'newRoom' => $newRoomLabel,
                'nights' => $nights,
                'priceDiffAmount' => (float) $priceDiffAmount,
            ];
        })->values();

        $totalPriceDiff = (float) $roomChanges->sum('priceDiffAmount');
        $changesCount = $roomChanges->count();
        $singleChange = $changesCount === 1 ? $roomChanges->first() : null;

        $financial = $this->rebookingRequestService->financialAssessment($leader);
        $refund = $leader->refund;

        return [
            'id' => 'RBK' . str_pad((string) $leader->id, 3, '0', STR_PAD_LEFT),
            'groupId' => (int) ($leader->rebooking_group_id ?: $leader->id),
            'rebookingIds' => $rows->map(fn (Rebooking $row) => (int) $row->id)->values()->all(),
            'originalId' => $originalBooking?->reference_number ?? 'N/A',
            'originalBookingRoomId' => $singleChange['originalBookingRoomId'] ?? null,
            'requestedRoomId' => $singleChange['requestedRoomId'] ?? null,
            'roomChangesCount' => $changesCount,
            'roomChanges' => $roomChanges->map(function (array $change) {
                return [
                    'rebookingId' => $change['rebookingId'],
                    'originalBookingRoomId' => $change['originalBookingRoomId'],
                    'requestedRoomId' => $change['requestedRoomId'],
                    'oldRoom' => $change['oldRoom'],
                    'newRoom' => $change['newRoom'],
                    'nights' => $change['nights'],
                    'priceDiff' => $this->formatPriceDiff((float) $change['priceDiffAmount']),
                ];
            })->values()->all(),
            'guest' => $originalBooking?->primaryGuest?->name ?? 'N/A',
            'phone' => $originalBooking?->primaryGuest?->phone ?? 'N/A',
            'email' => $originalBooking?->primaryGuest?->email ?? 'N/A',
            'oldRoom' => $singleChange['oldRoom'] ?? 'Multiple rooms',
            'newRoom' => $singleChange['newRoom'] ?? ($changesCount . ' room changes'),
            'oldCheckIn' => $this->formatDate($originalBooking?->check_in),
            'oldCheckOut' => $this->formatDate($originalBooking?->check_out),
            'newCheckIn' => $this->formatDate($newBooking?->check_in ?? $originalBooking?->check_in),
            'newCheckOut' => $this->formatDate($newBooking?->check_out ?? $originalBooking?->check_out),
            'nights' => $singleChange['nights'] ?? ($originalBooking?->nights ?? 0),
            'rebookedOn' => $leader->created_at?->format('Y-m-d'),
            'reason' => $leader->reason,
            'priceDiff' => $this->formatPriceDiff($totalPriceDiff),
            'status' => $this->formatStatus((string) $leader->status),
            'statusKey' => strtolower((string) $leader->status),
            'workflowStatus' => $financial['status'] ?? $leader->financial_status,
            'workflowStatusLabel' => $this->formatStatus((string) ($financial['status'] ?? $leader->financial_status)),
            'financial' => $financial,
            'canApprove' => (bool) ($financial['approval_ready'] ?? false),
            'canRequestPayment' => in_array($leader->status, [Rebooking::STATUS_PENDING, Rebooking::STATUS_AWAITING_PAYMENT], true)
                && ($financial['status'] ?? null) === Rebooking::FINANCIAL_ADDITIONAL_PAYMENT_REQUIRED,
            'canSendRefundReview' => $leader->status === Rebooking::STATUS_PENDING
                && ($financial['status'] ?? null) === Rebooking::FINANCIAL_REFUND_REVIEW,
            'canProcessRefund' => $leader->status === Rebooking::STATUS_UNDER_REVIEW
                && ! $refund
                && ($financial['status'] ?? null) === Rebooking::FINANCIAL_REFUND_REVIEW,
            'canReject' => in_array($leader->status, Rebooking::OPEN_STATUSES, true)
                && ($financial['status'] ?? null) !== Rebooking::FINANCIAL_PAYMENT_UNDER_REVIEW
                && $leader->adjustmentPayment?->payment_status !== 'completed',
            'canDecide' => in_array($leader->status, Rebooking::OPEN_STATUSES, true),
            'refundRecordedAwaitingApproval' => $refund !== null
                && $leader->status === Rebooking::STATUS_UNDER_REVIEW && ! $leader->finalized_at,
            'refund' => $refund ? [
                'amount' => (float) $refund->amount,
                'recipient' => $refund->recipient_name.' · *******'.$refund->recipient_account_last_four,
                'reference' => $refund->gcash_reference,
                'processedAt' => $refund->processed_at?->toIso8601String(),
                'processedBy' => $refund->processor?->name,
                'proofUrl' => $refund->proof_deleted_at ? null : url('/api/admin/rebookings/'.$leader->id.'/refund-proof'),
            ] : null,
            'note' => $leader->decision_note,
        ];
    }

    private function loadGroupedRowsForLeaders($leaderRows)
    {
        $groupIds = collect($leaderRows)
            ->map(fn (Rebooking $row) => (int) ($row->rebooking_group_id ?: $row->id))
            ->unique()
            ->values()
            ->all();

        if (count($groupIds) === 0) {
            return collect();
        }

        return Rebooking::with($this->rebookingRelations())
            ->where(function ($q) use ($groupIds) {
                $q->whereIn('rebooking_group_id', $groupIds)
                    ->orWhereIn('id', $groupIds);
            })
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Rebooking $row) => (int) ($row->rebooking_group_id ?: $row->id));
    }

    private function loadGroupedRowsForLeader(Rebooking $rebooking)
    {
        $groupId = (int) ($rebooking->rebooking_group_id ?: $rebooking->id);
        $groupRowsMap = $this->loadGroupedRowsForLeaders(collect([$rebooking]));
        return $groupRowsMap->get($groupId, collect([$rebooking]));
    }

    private function formatPriceDiff(float $diff): string
    {
        if ($diff > 0) {
            return '+₱' . number_format($diff, 2);
        }

        if ($diff < 0) {
            return '-₱' . number_format(abs($diff), 2);
        }

        return '₱0.00';
    }

    private function formatStatus(string $status): string
    {
        $statusMap = [
            'approved' => 'Approved',
            'pending' => 'Pending',
            'rejected' => 'Rejected',
            'confirmed' => 'Confirmed',
            'ready' => 'Ready for Approval',
            'additional_payment_required' => 'Additional Payment Required',
            'awaiting_payment' => 'Awaiting Payment',
            'payment_under_review' => 'Payment Verification',
            'refund_review' => 'Refund Review',
            'refund_completed' => 'Refund Completed',
            'under_review' => 'Under Review',
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return 'TBD';
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    private function storeRefundProof(?UploadedFile $file): array
    {
        if (! $file || ! $file->isValid()) throw new \RuntimeException('REFUND_PROOF_REQUIRED');
        $path = $file->getRealPath();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (! is_string($mime) || ! isset($extensions[$mime])) throw new \RuntimeException('REFUND_PROOF_REQUIRED');
        if ($mime === 'application/pdf') {
            $handle = fopen($path, 'rb');
            $signature = $handle ? fread($handle, 5) : '';
            if (is_resource($handle)) fclose($handle);
            if ($signature !== '%PDF-') throw new \RuntimeException('REFUND_PROOF_REQUIRED');
        } elseif (! @getimagesize($path)) {
            throw new \RuntimeException('REFUND_PROOF_REQUIRED');
        }
        $disk = 'manual_gcash_refunds';
        $storedPath = now()->format('Y/m').'/'.Str::uuid().'.'.$extensions[$mime];
        $stored = Storage::disk($disk)->putFileAs(dirname($storedPath), $file, basename($storedPath));
        if ($stored !== $storedPath) throw new \RuntimeException('REFUND_PROOF_REQUIRED');
        $contents = Storage::disk($disk)->get($storedPath);
        return [
            'proof_disk' => $disk, 'proof_path' => $storedPath,
            'proof_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'proof_mime_type' => $mime, 'proof_size' => strlen($contents), 'proof_sha256' => hash('sha256', $contents),
        ];
    }
}
