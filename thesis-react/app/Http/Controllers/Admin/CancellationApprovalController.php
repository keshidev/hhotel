<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\CancellationApprovalRequest;
use App\Models\ManualGcashRefund;
use App\Services\CancellationApprovalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CancellationApprovalController extends Controller
{
    private const IMAGE_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private CancellationApprovalService $cancellationApprovalService
    ) {
    }

    public function index(Request $request)
    {
        $query = CancellationApprovalRequest::query()
            ->with(['booking.primaryGuest', 'booking.bookingRooms.room', 'booking.payments', 'requester', 'approver', 'finalizer', 'cancellation', 'manualGcashRefund.processor'])
            ->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('booking', function ($bookingQuery) use ($search) {
                    $bookingQuery->where('reference_number', 'like', "%{$search}%")
                        ->orWhereHas('primaryGuest', function ($guestQuery) use ($search) {
                            $guestQuery->where('name', 'like', "%{$search}%");
                        });
                })->orWhere('id', (int) preg_replace('/[^0-9]/', '', $search));
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', strtolower((string) $request->status));
        }

        $rows = $query->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'requests' => $rows->getCollection()->map(fn (CancellationApprovalRequest $item) => $this->transform($item))->values(),
            'total' => $rows->total(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
        ]);
    }

    public function show($id)
    {
        $row = CancellationApprovalRequest::query()
            ->with(['booking.primaryGuest', 'booking.bookingRooms.room', 'booking.payments', 'requester', 'approver', 'finalizer', 'cancellation', 'manualGcashRefund.processor'])
            ->findOrFail($this->parseId($id));

        return response()->json($this->transform($row, true));
    }

    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'decision_note' => 'nullable|string|max:500',
        ]);

        try {
            $row = $this->cancellationApprovalService->approve(
                requestId: $this->parseId($id),
                admin: $request->user(),
                decisionNote: $validated['decision_note'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->handleException($e, 'Cancellation Approval Attempt Blocked', $request->user(), $this->parseId($id));
        }

        return response()->json([
            'success' => true,
            'message' => $row->status === 'refund_pending' ? 'Reservation cancelled. Refund pending.' : 'Reservation cancelled. No further action is required.',
            'request' => $this->transform($row, true),
        ]);
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'decision_note' => 'required|string|max:500',
        ]);

        try {
            $row = $this->cancellationApprovalService->reject(
                requestId: $this->parseId($id),
                admin: $request->user(),
                decisionNote: $validated['decision_note']
            );
        } catch (\RuntimeException $e) {
            return $this->handleException($e, 'Cancellation Rejection Attempt Blocked', $request->user(), $this->parseId($id));
        }

        return response()->json([
            'success' => true,
            'message' => 'Cancellation request rejected.',
            'request' => $this->transform($row, true),
        ]);
    }

    public function processRefund(Request $request, $id)
    {
        $validated = $request->validate([
            'refund_note' => 'nullable|string|max:500',
            'recipient_name' => 'nullable|string|max:120',
            'recipient_account' => 'nullable|string|max:32',
            'gcash_reference' => 'nullable|string|max:80',
            'processed_at' => 'nullable|date|before_or_equal:now',
            'refund_reason' => 'nullable|string|max:1000',
            'manual_transfer_confirmed' => 'nullable|accepted',
            'proof' => 'nullable|file|max:5120',
        ]);

        $storedProof = null;
        if ($request->hasFile('proof')) {
            $storedProof = $this->storeRefundProof($request->file('proof'));
            $validated['proof'] = $storedProof;
        }

        try {
            $row = $this->cancellationApprovalService->processRefund(
                requestId: $this->parseId($id),
                admin: $request->user(),
                refundDetails: $validated
            );
        } catch (\RuntimeException $e) {
            $this->deleteStoredProof($storedProof);
            return $this->handleException($e, 'Cancellation Refund Attempt Blocked', $request->user(), $this->parseId($id));
        } catch (\Throwable $e) {
            $this->deleteStoredProof($storedProof);
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Refund completion recorded successfully.',
            'request' => $this->transform($row, true),
        ]);
    }

    public function refundProof($id): StreamedResponse
    {
        $request = CancellationApprovalRequest::with('manualGcashRefund')->findOrFail($this->parseId($id));
        $refund = $request->manualGcashRefund;
        if ($refund?->proof_deleted_at) {
            abort(410, 'Refund proof was securely removed after the approved retention period.');
        }

        if (! $refund || ! $refund->proof_path || ! Storage::disk($refund->proof_disk)->exists($refund->proof_path)) {
            abort(404);
        }

        $contents = Storage::disk($refund->proof_disk)->get($refund->proof_path);
        if (! hash_equals((string) $refund->proof_sha256, hash('sha256', $contents))) {
            Log::critical('Manual GCash refund proof integrity check failed', ['refund_id' => $refund->id]);
            abort(409, 'Refund proof integrity verification failed.');
        }

        AuditHelper::log(
            actionActivity: 'Manual GCash Refund Proof Viewed',
            modulePage: 'Cancellation Module',
            modelType: 'ManualGcashRefund',
            modelId: $refund->id,
            recordAffected: 'Manual GCash refund #'.$refund->id,
            oldValues: null,
            newValues: ['viewer' => auth()->user()?->name],
            action: 'viewed'
        );

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $refund->proof_original_name, [
            'Content-Type' => $refund->proof_mime_type,
            'Content-Length' => (string) $refund->proof_size,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }

    public function finalize(Request $request, $id)
    {
        $validated = $request->validate([
            'finalize_note' => 'nullable|string|max:500',
        ]);

        try {
            $row = $this->cancellationApprovalService->finalize(
                requestId: $this->parseId($id),
                admin: $request->user(),
                finalizeNote: $validated['finalize_note'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->handleException($e, 'Cancellation Finalize Attempt Blocked', $request->user(), $this->parseId($id));
        }

        return response()->json([
            'success' => true,
            'message' => 'Cancellation finalized successfully.',
            'request' => $this->transform($row, true),
        ]);
    }

    private function handleException(
        \RuntimeException $e,
        ?string $actionActivity = null,
        ?\App\Models\User $actor = null,
        ?int $requestId = null
    )
    {
        $code = $e->getMessage();

        if (str_starts_with($code, 'BOOKING_NOT_CANCELLABLE:')) {
            $status = substr($code, strlen('BOOKING_NOT_CANCELLABLE:'));
            return response()->json([
                'success' => false,
                'message' => "Booking cannot be cancelled while in '{$status}' status.",
            ], 409);
        }

        if (str_starts_with($code, 'BOOKING_LIFECYCLE_LOCKED:')) {
            return response()->json([
                'success' => false,
                'message' => 'Booking lifecycle is locked by an open cancellation request. Resolve that request first.',
            ], 409);
        }

        $message = match ($code) {
            'INVALID_STATUS' => 'This request cannot be processed from its current status.',
            'OPEN_REQUEST_EXISTS' => 'An open request already exists for this booking.',
            'BOOKING_NOT_REQUESTABLE' => 'Booking cannot be cancelled from its current status.',
            'REFUND_EXCEEDS_PAID' => 'Refund amount exceeds paid amount.',
            'REFUND_METHOD_REQUIRED' => 'Refund method is required for refundable requests.',
            'ALREADY_FINALIZED' => 'Request has already been finalized.',
            'ALREADY_REFUNDED' => 'Refund has already been processed for this request.',
            'REFUND_NOT_PROCESSED' => 'Refund must be processed before finalizing cancellation.',
            'REFUND_AMOUNT_INVALID' => 'Refund amount must be greater than zero.',
            'MANUAL_TRANSFER_CONFIRMATION_REQUIRED' => 'Confirm that the GCash refund was actually sent before recording completion.',
            'REFUND_RECIPIENT_REQUIRED' => 'GCash refund recipient name is required.',
            'REFUND_RECIPIENT_ACCOUNT_INVALID' => 'Enter a valid Philippine GCash mobile number.',
            'REFUND_REFERENCE_INVALID' => 'Enter a valid GCash refund reference number.',
            'REFUND_REFERENCE_DUPLICATE' => 'This GCash refund reference is already recorded.',
            'REFUND_REASON_REQUIRED' => 'A clear refund reason of at least 10 characters is required.',
            'REFUND_PROOF_REQUIRED' => 'Upload the official GCash refund proof before completing the refund.',
            'REFUND_PROCESSED_AT_INVALID' => 'Enter a valid refund date and time after the original payment and not in the future.',
            'BOOKING_ALREADY_CANCELLED' => 'Booking is already cancelled.',
            'REASON_REQUIRED' => 'Cancellation reason is required.',
            default => 'Unable to process cancellation request.',
        };

        $statusCode = match ($code) {
            'INVALID_STATUS', 'ALREADY_FINALIZED', 'ALREADY_REFUNDED', 'BOOKING_ALREADY_CANCELLED', 'REFUND_NOT_PROCESSED' => 409,
            'OPEN_REQUEST_EXISTS' => 409,
            'REASON_REQUIRED', 'REFUND_EXCEEDS_PAID', 'REFUND_METHOD_REQUIRED', 'REFUND_AMOUNT_INVALID', 'BOOKING_NOT_REQUESTABLE',
            'MANUAL_TRANSFER_CONFIRMATION_REQUIRED', 'REFUND_RECIPIENT_REQUIRED', 'REFUND_RECIPIENT_ACCOUNT_INVALID',
            'REFUND_REFERENCE_INVALID', 'REFUND_REASON_REQUIRED', 'REFUND_PROOF_REQUIRED', 'REFUND_PROCESSED_AT_INVALID' => 422,
            'REFUND_REFERENCE_DUPLICATE' => 409,
            default => 422,
        };

        if ($actionActivity !== null && $requestId !== null) {
            AuditHelper::log(
                actionActivity: $actionActivity,
                modulePage: 'Cancellation Module',
                modelType: 'CancellationApprovalRequest',
                modelId: $requestId,
                recordAffected: 'Cancellation Request #' . $requestId,
                oldValues: null,
                newValues: [
                    'result' => 'blocked',
                    'reason_code' => $code,
                    'message' => $message,
                ],
                action: 'blocked',
                actorUser: $actor
            );
        }

        return response()->json([
            'success' => false,
            'message' => $message,
        ], $statusCode);
    }

    private function parseId($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function transform(CancellationApprovalRequest $request, bool $full = false): array
    {
        $booking = $request->booking;
        $primaryGuest = $booking?->primaryGuest;
        $room = $booking?->bookingRooms?->first()?->room;

        $payload = [
            'id' => 'CAR' . str_pad((string) $request->id, 4, '0', STR_PAD_LEFT),
            'requestId' => $request->id,
            'bookingId' => $booking?->reference_number ?? 'N/A',
            'bookingStatus' => $booking?->booking_status ?? 'unknown',
            'guest' => $primaryGuest?->name ?? 'N/A',
            'room' => $room ? ($room->room_type . ' ' . $room->room_number) : 'N/A',
            'checkIn' => $this->formatDate($booking?->check_in),
            'checkOut' => $this->formatDate($booking?->check_out),
            'reason' => $request->reason,
            'refundAmount' => (float) $request->refund_amount,
            'refundMethod' => $request->refund_method,
            'status' => $request->status,
            'statusLabel' => $this->statusLabel($request->status),
            'requestedBy' => $request->requester?->name ?? 'Unknown',
            'requestedAt' => $request->created_at?->toDateTimeString(),
            'approvedBy' => $request->approver?->name,
            'approvedAt' => $request->approved_at?->toDateTimeString(),
            'rejectedAt' => $request->rejected_at?->toDateTimeString(),
            'finalizedBy' => $request->finalizer?->name,
            'finalizedAt' => $request->finalized_at?->toDateTimeString(),
            'refundProcessedAt' => $request->refund_processed_at?->toDateTimeString(),
            'decisionNote' => $request->decision_note,
            'requestNote' => $request->request_note,
            'canApprove' => $request->status === CancellationApprovalRequest::STATUS_PENDING_APPROVAL
                || ($booking?->booking_status !== 'cancelled' && in_array($request->status, ['approved', 'refund_pending', 'refunded'], true)),
            'previouslyApproved' => in_array($request->status, ['approved', 'refund_pending', 'refunded'], true),
            'canReject' => $request->status === CancellationApprovalRequest::STATUS_PENDING_APPROVAL,
            'canProcessRefund' => $request->status === CancellationApprovalRequest::STATUS_REFUND_PENDING && $booking?->booking_status === 'cancelled',
            'canFinalize' => false,
            'refundStatusLabel' => $request->refund_processed_at ? 'Refund Completed'
                : ((float) $request->refund_amount > 0 ? ($request->status === 'pending_approval' ? 'Awaiting Approval' : ($request->status === 'rejected' ? 'Not Approved' : 'Refund Pending')) : 'No Refund Due'),
        ];

        $manualSource = $booking?->payments?->first(fn ($payment) => $payment->provider === 'manual_gcash' && $payment->payment_type !== 'refund');
        $manualRefund = $request->manualGcashRefund;
        $payload['requiresManualGcashEvidence'] = $manualSource !== null;
        $payload['manualGcashRefund'] = $manualRefund ? [
            'status' => $manualRefund->status,
            'approvedAmount' => (float) $manualRefund->approved_amount,
            'recipientName' => $manualRefund->recipient_name,
            'recipientAccount' => $manualRefund->recipient_account_last_four
                ? '*******'.$manualRefund->recipient_account_last_four
                : null,
            'gcashReference' => $manualRefund->gcash_reference,
            'processedAt' => $manualRefund->processed_at?->toDateTimeString(),
            'processedBy' => $manualRefund->processor?->name,
            'reason' => $manualRefund->reason,
            'proofRetentionExpiresAt' => $manualRefund->proof_retention_expires_at?->toDateTimeString(),
            'proofDeletedAt' => $manualRefund->proof_deleted_at?->toDateTimeString(),
            'proofUrl' => $manualRefund->proof_path && ! $manualRefund->proof_deleted_at
                ? url('/api/admin/cancellation-requests/'.$request->id.'/refund-proof')
                : null,
        ] : null;

        if ($full) {
            $payload['refundRecipientSuggestion'] = $booking
                ? app(\App\Services\CancellationRefundRecipientService::class)->adminSuggestion($request)
                : null;
            $payload['cancellationId'] = $request->cancellation_id;
            $payload['cancellationRecordId'] = $request->cancellation
                ? ('CAN' . str_pad((string) $request->cancellation->id, 3, '0', STR_PAD_LEFT))
                : null;
        }

        return $payload;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            CancellationApprovalRequest::STATUS_PENDING_APPROVAL => 'Pending Approval',
            CancellationApprovalRequest::STATUS_APPROVED => 'Approved',
            CancellationApprovalRequest::STATUS_REJECTED => 'Rejected',
            CancellationApprovalRequest::STATUS_REFUND_PENDING => 'Refund Pending',
            CancellationApprovalRequest::STATUS_REFUNDED => 'Refunded',
            CancellationApprovalRequest::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return 'N/A';
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    private function storeRefundProof(?UploadedFile $file): array
    {
        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages(['proof' => 'Upload a valid refund proof file.']);
        }

        $realPath = $file->getRealPath();
        $detectedMime = $realPath ? (new \finfo(FILEINFO_MIME_TYPE))->file($realPath) : false;
        $extension = null;
        if (is_string($detectedMime) && isset(self::IMAGE_MIME_TYPES[$detectedMime])) {
            $image = @getimagesize($realPath);
            if (! $image || ($image['mime'] ?? null) !== $detectedMime) {
                throw ValidationException::withMessages(['proof' => 'The refund proof image content is invalid.']);
            }
            $extension = self::IMAGE_MIME_TYPES[$detectedMime];
        } elseif ($detectedMime === 'application/pdf') {
            $handle = fopen($realPath, 'rb');
            $signature = $handle ? fread($handle, 5) : '';
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($signature !== '%PDF-') {
                throw ValidationException::withMessages(['proof' => 'The refund proof PDF content is invalid.']);
            }
            $extension = 'pdf';
        } else {
            throw ValidationException::withMessages(['proof' => 'Refund proof must be a JPEG, PNG, WebP, or PDF file.']);
        }

        $disk = 'manual_gcash_refunds';
        $path = now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
        if ($stored !== $path || ! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('Refund proof could not be stored securely.');
        }
        $contents = Storage::disk($disk)->get($path);

        return [
            'proof_disk' => $disk,
            'proof_path' => $path,
            'proof_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'proof_mime_type' => $detectedMime,
            'proof_size' => strlen($contents),
            'proof_sha256' => hash('sha256', $contents),
        ];
    }

    private function deleteStoredProof(?array $proof): void
    {
        // A post-commit response failure must never delete committed refund evidence.
        if ($proof && ManualGcashRefund::where('proof_path', $proof['proof_path'] ?? '')->where('proof_disk', $proof['proof_disk'] ?? '')->exists()) {
            return;
        }
        if ($proof && ! empty($proof['proof_disk']) && ! empty($proof['proof_path'])) {
            Storage::disk($proof['proof_disk'])->delete($proof['proof_path']);
        }
    }
}
