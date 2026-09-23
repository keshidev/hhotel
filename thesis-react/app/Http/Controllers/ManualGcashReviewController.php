<?php

namespace App\Http\Controllers;

use App\Helpers\AuditHelper;
use App\Mail\BookingConfirmation;
use App\Mail\ManualGcashStatusMail;
use App\Mail\BookingWorkflowDecisionMail;
use App\Models\Booking;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Services\CancellationApprovalService;
use App\Services\PaymentAccessSessionService;
use App\Services\RoomAssignmentService;
use App\Services\RebookingAdjustmentService;
use App\Services\StaffNotificationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManualGcashReviewController extends Controller
{
    public function __construct(
        private CancellationApprovalService $cancellationApprovalService,
        private RoomAssignmentService $roomAssignmentService,
        private PaymentAccessSessionService $paymentAccessSessions,
        private StaffNotificationService $staffNotificationService,
        private RebookingAdjustmentService $rebookingAdjustments
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending_verification,escalated,approved,rejected'],
            'queue' => ['nullable', 'in:ready,admin,history'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $queue = $validated['queue'] ?? null;
        $query = ManualGcashSubmission::query()
            ->with(['booking.primaryGuest', 'payment', 'reviewer:id,name'])
            ->when($queue === 'ready', fn ($builder) => $builder->where('status', ManualGcashSubmission::STATUS_PENDING))
            ->when($queue === 'admin', fn ($builder) => $builder->where('status', ManualGcashSubmission::STATUS_ESCALATED))
            ->when($queue === 'history', function ($builder) use ($validated) {
                $historyStatus = $validated['status'] ?? null;
                return $historyStatus && in_array($historyStatus, [
                    ManualGcashSubmission::STATUS_APPROVED,
                    ManualGcashSubmission::STATUS_REJECTED,
                ], true)
                    ? $builder->where('status', $historyStatus)
                    : $builder->whereIn('status', [
                        ManualGcashSubmission::STATUS_APPROVED,
                        ManualGcashSubmission::STATUS_REJECTED,
                    ]);
            })
            ->when(! $queue && ($validated['status'] ?? null), fn ($builder) => $builder->where('status', $validated['status']))
            ->when($validated['search'] ?? null, function ($builder, $search) {
                $term = '%'.trim($search).'%';
                $builder->where(function ($nested) use ($term) {
                    $nested->where('transaction_reference', 'like', $term)
                        ->orWhere('sender_name', 'like', $term)
                        ->orWhereHas('booking', fn ($booking) => $booking->where('reference_number', 'like', $term))
                        ->orWhereHas('booking.primaryGuest', fn ($guest) => $guest->where('name', 'like', $term));
                });
            })
            ->orderByRaw("CASE status WHEN 'escalated' THEN 0 WHEN 'pending_verification' THEN 1 ELSE 2 END")
            ->orderByDesc('submitted_at');

        $rows = $query->paginate(25)->through(fn (ManualGcashSubmission $submission) => $this->payload($submission));

        return response()->json($rows);
    }

    public function show(ManualGcashSubmission $submission)
    {
        return response()->json($this->payload($submission->load([
            'booking.primaryGuest',
            'booking.bookingRooms.room',
            'payment',
            'reviewer:id,name',
        ])));
    }

    public function proof(ManualGcashSubmission $submission): StreamedResponse
    {
        if ($submission->proof_deleted_at) {
            abort(410, 'Payment proof was securely removed after the approved retention period.');
        }

        if (! Storage::disk($submission->proof_disk)->exists($submission->proof_path)) {
            abort(404);
        }

        $contents = Storage::disk($submission->proof_disk)->get($submission->proof_path);
        if (! hash_equals($submission->proof_sha256, hash('sha256', $contents))) {
            Log::critical('Manual GCash proof integrity check failed', ['submission_id' => $submission->id]);
            abort(409, 'Proof integrity verification failed.');
        }

        AuditHelper::log(
            actionActivity: 'Manual GCash Proof Viewed',
            modulePage: 'Payment Module',
            modelType: 'ManualGcashSubmission',
            modelId: $submission->id,
            recordAffected: 'Manual GCash proof #'.$submission->id,
            oldValues: null,
            newValues: ['viewer' => auth()->user()?->name],
            action: 'viewed'
        );

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $submission->proof_original_name, [
            'Content-Type' => $submission->proof_mime_type,
            'Content-Length' => (string) $submission->proof_size,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }

    public function approve(Request $request, ManualGcashSubmission $submission)
    {
        $validated = $request->validate([
            'merchant_reference' => ['required', 'string', 'min:6', 'max:80'],
            'verified_amount' => ['required', 'numeric', 'min:0.01'],
            'merchant_paid_at' => ['required', 'date', 'before_or_equal:now'],
            'merchant_record_confirmed' => ['accepted'],
            'admin_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $isAdmin = $user?->role === 'admin';
        try {
            $result = DB::transaction(function () use ($submission, $validated, $user, $isAdmin) {
                $lockedSubmission = ManualGcashSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
                $payment = Payment::whereKey($lockedSubmission->payment_id)->lockForUpdate()->firstOrFail();
                $booking = Booking::whereKey($lockedSubmission->booking_id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedSubmission->status, [ManualGcashSubmission::STATUS_PENDING, ManualGcashSubmission::STATUS_ESCALATED], true)) {
                    throw ValidationException::withMessages(['submission' => 'This proof has already been reviewed.']);
                }

                if ($lockedSubmission->status === ManualGcashSubmission::STATUS_ESCALATED && ! $isAdmin) {
                    abort(403, 'This overdue review requires an administrator.');
                }

                $merchantReference = $this->normalizeReference($validated['merchant_reference']);
                $referenceMatches = $merchantReference
                    === $lockedSubmission->normalized_transaction_reference;
                $amountMatches = (int) round((float) $validated['verified_amount'] * 100)
                    === (int) round((float) $lockedSubmission->submitted_amount * 100)
                    && (int) round((float) $validated['verified_amount'] * 100)
                    === (int) round((float) $payment->amount * 100);
                $timeMatches = Carbon::parse($validated['merchant_paid_at'])->format('Y-m-d H:i')
                    === $lockedSubmission->paid_at->format('Y-m-d H:i');
                $exactMatch = $referenceMatches && $amountMatches && $timeMatches;
                $adminOverride = $isAdmin && (bool) ($validated['admin_override'] ?? false);

                if (! $amountMatches) {
                    throw ValidationException::withMessages([
                        'verified_amount' => 'The merchant amount must exactly equal the required downpayment. Underpayments cannot be overridden.',
                    ]);
                }

                if (! $exactMatch && ! $adminOverride) {
                    throw ValidationException::withMessages([
                        'merchant_record' => $isAdmin
                            ? 'Merchant details do not exactly match. Select admin override and provide a reason to continue.'
                            : 'Receptionists may approve only when reference, amount, and payment time exactly match merchant records.',
                    ]);
                }

                if ($adminOverride && mb_strlen(trim((string) ($validated['override_reason'] ?? ''))) < 10) {
                    throw ValidationException::withMessages([
                        'override_reason' => 'Admin override requires a clear reason of at least 10 characters.',
                    ]);
                }

                $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
                    booking: $booking,
                    actionCode: 'manual_gcash_approval'
                );

                $lockedSubmission->update([
                    'status' => ManualGcashSubmission::STATUS_APPROVED,
                    'active_reference_claim' => $merchantReference,
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'review_reason' => $adminOverride ? trim($validated['override_reason']) : 'Exact merchant record match.',
                    'admin_override' => $adminOverride,
                ]);
                $payment->update([
                    'payment_status' => 'completed',
                    'lifecycle_status' => Payment::LIFECYCLE_PAID,
                    'lifecycle_message' => null,
                    'paid_amount' => $lockedSubmission->submitted_amount,
                    'paid_at' => $lockedSubmission->paid_at,
                    'verified_by' => $user->id,
                    'verified_at' => now(),
                    'provider_reference' => trim($validated['merchant_reference']),
                    'notes' => 'Manual GCash payment verified against merchant records.',
                ]);
                if ($payment->purpose !== Payment::PURPOSE_REBOOKING_ADJUSTMENT) {
                    $booking->update([
                        'booking_status' => 'confirmed',
                        'reservation_status' => 'confirmed',
                        'cancelled_reason' => null,
                    ]);
                }

                return [
                    'submission' => $lockedSubmission,
                    'payment' => $payment,
                    'booking' => $booking,
                    'admin_override' => $adminOverride,
                ];
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'active_reference_claim')) {
                throw ValidationException::withMessages([
                    'merchant_reference' => 'This merchant GCash reference is already attached to another active or approved payment.',
                ]);
            }

            throw $exception;
        }

        $isRebookingAdjustment = $result['payment']->purpose === Payment::PURPOSE_REBOOKING_ADJUSTMENT;
        $assignment = ['unassigned' => []];
        if ($isRebookingAdjustment) {
            $this->rebookingAdjustments->markPaymentVerified($result['payment']);
        } else {
            $assignment = $this->roomAssignmentService->assignRoomToBooking(
                $result['booking']->fresh(),
                $request->user()->name
            );
            if (! empty($assignment['unassigned'])) {
                $result['payment']->update([
                    'lifecycle_status' => Payment::LIFECYCLE_ASSIGNMENT_FAILED,
                    'lifecycle_message' => 'Payment was approved, but one or more rooms require manual assignment.',
                ]);
            }
        }

        $this->paymentAccessSessions->revoke($result['booking']->id, 'manual_gcash_approved');
        if (! $isRebookingAdjustment) {
            try {
                $this->staffNotificationService->notifyNewBooking($result['booking']->fresh());
            } catch (\Throwable $exception) {
                Log::warning('Manual GCash confirmed booking staff notification failed', [
                    'booking_id' => $result['booking']->id,
                    'error' => $exception->getMessage(),
                ]);
            }
            $this->queueConfirmation($result['booking']->id);
        } elseif ($result['booking']->primaryGuest?->email) {
            Mail::to($result['booking']->primaryGuest->email)->queue(new BookingWorkflowDecisionMail(
                booking: $result['booking']->fresh('primaryGuest'),
                heading: 'Additional Payment Verified',
                status: 'under_review',
                messageBody: 'Your additional room-change payment was verified. Hotel staff will complete a final availability check and notify you when the requested room change is approved.'
            ));
        }
        AuditHelper::log(
            actionActivity: $result['admin_override'] ? 'Manual GCash Approved with Admin Override' : 'Manual GCash Approved',
            modulePage: 'Payment Module',
            modelType: 'ManualGcashSubmission',
            modelId: $result['submission']->id,
            recordAffected: 'Booking '.$result['booking']->reference_number,
            oldValues: ['status' => $submission->status],
            newValues: ['status' => 'approved', 'payment_status' => 'completed'],
            action: 'updated'
        );

        return response()->json([
            'success' => true,
            'message' => $isRebookingAdjustment
                ? 'Additional payment approved. The rebooking is ready for final staff approval.'
                : (empty($assignment['unassigned'])
                    ? 'Payment approved and booking confirmed.'
                    : 'Payment approved. Room assignment requires staff attention.'),
            'data' => $this->payload($result['submission']->fresh(['booking.primaryGuest', 'payment', 'reviewer:id,name'])),
        ]);
    }

    public function reject(Request $request, ManualGcashSubmission $submission)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $user = $request->user();

        $result = DB::transaction(function () use ($submission, $validated, $user) {
            $lockedSubmission = ManualGcashSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($lockedSubmission->payment_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::with('primaryGuest')->whereKey($lockedSubmission->booking_id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedSubmission->status, [ManualGcashSubmission::STATUS_PENDING, ManualGcashSubmission::STATUS_ESCALATED], true)) {
                throw ValidationException::withMessages(['submission' => 'This proof has already been reviewed.']);
            }

            if ($lockedSubmission->status === ManualGcashSubmission::STATUS_ESCALATED && $user?->role !== 'admin') {
                abort(403, 'This overdue review requires an administrator.');
            }

            $lockedSubmission->update([
                'status' => ManualGcashSubmission::STATUS_REJECTED,
                'active_reference_claim' => null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_reason' => trim($validated['reason']),
            ]);

            $canRetry = $payment->payment_due_at?->isFuture()
                && (int) $payment->submission_attempts < max(1, (int) config('payment.manual_gcash.max_submission_attempts', 3));

            $resumeToken = null;
            $attemptsRemaining = max(
                0,
                max(1, (int) config('payment.manual_gcash.max_submission_attempts', 3)) - (int) $payment->submission_attempts
            );

            if ($canRetry) {
                $resumeToken = Str::random(64);
                $payment->update([
                    'lifecycle_status' => Payment::LIFECYCLE_REJECTED,
                    'lifecycle_message' => 'Payment proof was rejected. A corrected proof may be submitted before the deadline.',
                    'review_due_at' => null,
                    'review_hold_until' => null,
                ]);
                $booking->update([
                    'expires_at' => $payment->payment_due_at,
                    'payment_bootstrap_token_hash' => hash('sha256', $resumeToken),
                    'payment_bootstrap_expires_at' => $payment->payment_due_at,
                ]);
            } else {
                $payment->update([
                    'payment_status' => 'failed',
                    'lifecycle_status' => Payment::LIFECYCLE_EXPIRED,
                    'lifecycle_message' => 'Payment proof was rejected and no further submission is allowed.',
                ]);
                $booking->update([
                    'payment_bootstrap_token_hash' => null,
                    'payment_bootstrap_expires_at' => null,
                ]);
                if ($payment->purpose !== Payment::PURPOSE_REBOOKING_ADJUSTMENT) {
                    $this->cancellationApprovalService->cancelLockedImmediately(
                        booking: $booking,
                        payload: [
                            'reason' => 'Manual GCash proof rejected: '.trim($validated['reason']),
                            'cancelled_reason_code' => 'manual_gcash_rejected',
                            'refund_status' => 'none',
                            'refund_amount' => 0,
                            'request_note' => 'Manual GCash proof review.',
                        ],
                        actorUser: $user,
                        actorLabel: $user->name
                    );
                }
            }

            $this->paymentAccessSessions->revoke($booking->id, 'manual_gcash_proof_rejected');

            return [
                'submission' => $lockedSubmission,
                'booking' => $booking,
                'payment' => $payment,
                'can_retry' => $canRetry,
                'resume_token' => $resumeToken,
                'attempts_remaining' => $attemptsRemaining,
            ];
        }, 3);

        if (! $result['can_retry'] && $result['payment']->purpose === Payment::PURPOSE_REBOOKING_ADJUSTMENT) {
            $this->rebookingAdjustments->markPaymentClosed($result['payment']);
        }

        $resumeUrl = $result['can_retry']
            ? route('manual-gcash.resume', ['token' => $result['resume_token']])
            : null;
        $this->queueStatusEmail(
            $result['booking'],
            $result['submission'],
            'rejected',
            $validated['reason'],
            $result['can_retry'],
            $resumeUrl,
            $result['payment']->payment_due_at?->format('M j, Y g:i A'),
            $result['attempts_remaining']
        );
        AuditHelper::log(
            actionActivity: 'Manual GCash Proof Rejected',
            modulePage: 'Payment Module',
            modelType: 'ManualGcashSubmission',
            modelId: $result['submission']->id,
            recordAffected: 'Booking '.$result['booking']->reference_number,
            oldValues: ['status' => $submission->status],
            newValues: ['status' => 'rejected', 'can_retry' => $result['can_retry']],
            action: 'updated'
        );

        return response()->json([
            'success' => true,
            'message' => $result['can_retry']
                ? 'Proof rejected. The guest may submit a correction before the deadline.'
                : 'Proof rejected and booking closed.',
        ]);
    }

    private function payload(ManualGcashSubmission $submission): array
    {
        $submission->loadMissing(['booking.primaryGuest', 'payment', 'reviewer:id,name']);
        $maxAttempts = max(1, (int) config('payment.manual_gcash.max_submission_attempts', 3));
        $attemptsUsed = (int) ($submission->payment?->submission_attempts ?? 0);
        $attemptsRemaining = max(0, $maxAttempts - $attemptsUsed);
        $canRetry = $submission->payment?->payment_status === 'pending'
            && $submission->payment?->payment_due_at?->isFuture()
            && $attemptsRemaining > 0;

        return [
            'id' => $submission->id,
            'booking_id' => $submission->booking_id,
            'booking_reference' => $submission->booking?->reference_number,
            'guest_name' => $submission->booking?->primaryGuest?->name,
            'attempt_number' => $submission->attempt_number,
            'submission_source' => $submission->submission_source,
            'transaction_reference' => $submission->transaction_reference,
            'sender_name' => $submission->sender_name,
            'submitted_amount' => (float) $submission->submitted_amount,
            'required_amount' => (float) $submission->payment?->amount,
            'purpose' => $submission->payment?->purpose ?: Payment::PURPOSE_BOOKING,
            'rebooking_id' => $submission->payment?->rebooking_id,
            'paid_at' => $submission->paid_at?->toIso8601String(),
            'paid_at_local' => $submission->paid_at?->format('Y-m-d\TH:i'),
            'status' => $submission->status,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'review_due_at' => $submission->review_due_at?->toIso8601String(),
            'escalation_due_at' => $submission->escalation_due_at?->toIso8601String(),
            'reviewed_by' => $submission->reviewer?->name,
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'review_reason' => $submission->review_reason,
            'admin_override' => $submission->admin_override,
            'payment_due_at' => $submission->payment?->payment_due_at?->toIso8601String(),
            'attempts_used' => $attemptsUsed,
            'attempts_remaining' => $attemptsRemaining,
            'can_retry' => $canRetry,
            'proof' => [
                'name' => $submission->proof_original_name,
                'mime_type' => $submission->proof_mime_type,
                'size' => $submission->proof_size,
                'available' => $submission->proof_deleted_at === null,
                'is_staff_attestation' => $submission->submission_source === ManualGcashSubmission::SOURCE_FRONT_DESK,
                'retention_expires_at' => $submission->proof_retention_expires_at?->toIso8601String(),
                'deleted_at' => $submission->proof_deleted_at?->toIso8601String(),
                'url' => $submission->proof_deleted_at === null
                    ? url('/api/manual-gcash-reviews/'.$submission->id.'/proof')
                    : null,
            ],
        ];
    }

    private function normalizeReference(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($reference)) ?? '');
    }

    private function queueConfirmation(int $bookingId): void
    {
        $booking = Booking::with(['primaryGuest', 'bookingRooms.room', 'payments', 'promoCode'])->find($bookingId);
        if (! $booking?->primaryGuest?->email) {
            return;
        }

        try {
            Mail::to($booking->primaryGuest->email)->queue(new BookingConfirmation($booking));
        } catch (\Throwable $exception) {
            Log::error('Manual GCash confirmation email could not be queued', [
                'booking_id' => $bookingId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function queueStatusEmail(
        Booking $booking,
        ManualGcashSubmission $submission,
        string $status,
        string $reason,
        bool $canRetry = false,
        ?string $resumeUrl = null,
        ?string $paymentDueAt = null,
        int $attemptsRemaining = 0
    ): void
    {
        if (! $booking->primaryGuest?->email) {
            return;
        }

        try {
            Mail::to($booking->primaryGuest->email)->queue(new ManualGcashStatusMail(
                $booking,
                $submission,
                $status,
                $reason,
                $canRetry,
                $resumeUrl,
                $paymentDueAt,
                $attemptsRemaining
            ));
        } catch (\Throwable $exception) {
            Log::error('Manual GCash rejection email could not be queued', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
