<?php

namespace App\Http\Controllers\Client;

use App\Helpers\AuditHelper;
use App\Helpers\NotificationHelper;
use App\Http\Controllers\Controller;
use App\Mail\ManualGcashStatusMail;
use App\Models\Booking;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Services\GuestPaymentAuthorizationService;
use App\Services\ManualGcashConfigurationService;
use App\Services\PaymentProviderService;
use App\Services\RebookingAdjustmentService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManualGcashPaymentController extends Controller
{
    private const IMAGE_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private PaymentProviderService $providers,
        private ManualGcashConfigurationService $configuration,
        private GuestPaymentAuthorizationService $authorization,
        private RebookingAdjustmentService $rebookingAdjustments
    ) {}

    public function prepare(Request $request, int $bookingId)
    {
        if (! $this->providers->uses(PaymentProviderService::MANUAL_GCASH)) {
            return response()->json(['message' => 'Manual GCash is not the active payment option.'], 409);
        }

        if (($issues = $this->providers->operationalIssues()) !== []) {
            return response()->json([
                'message' => 'Manual GCash payment is temporarily unavailable.',
                'issues' => $issues,
            ], 503);
        }

        $result = DB::transaction(function () use ($request, $bookingId) {
            $booking = Booking::with(['primaryGuest', 'bookingRooms.room'])
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                return ['error' => 404, 'message' => 'Booking not found.'];
            }

            $authorization = $this->authorization->authorize($request, $booking);
            if (! $authorization['authorized']) {
                return ['error' => $authorization['status'], 'message' => $authorization['message']];
            }

            $payment = $booking->payments()
                ->where('provider', PaymentProviderService::MANUAL_GCASH)
                ->where('payment_method', 'gcash')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return ['error' => 404, 'message' => 'No manual GCash payment is available for this booking.'];
            }

            if ($payment->payment_status === 'completed') {
                return ['error' => 409, 'message' => 'This payment has already been approved.'];
            }

            if (in_array($booking->booking_status, ['cancelled', 'no_show', 'checked_out'], true)) {
                return ['error' => 410, 'message' => 'This booking is no longer accepting payment.'];
            }

            $dueAt = $payment->payment_due_at
                ?? now()->addMinutes(max(5, (int) config('payment.manual_gcash.payment_window_minutes', 30)));

            if (! $payment->payment_due_at) {
                $payment->update([
                    'payment_due_at' => $dueAt,
                    'lifecycle_status' => Payment::LIFECYCLE_AWAITING_PAYMENT,
                ]);
                $booking->update(['expires_at' => $dueAt]);
            }

            if ($dueAt->isPast() && ! $this->hasProtectedSubmission($payment)) {
                return [
                    'error' => 410,
                    'message' => $payment->purpose === Payment::PURPOSE_REBOOKING_ADJUSTMENT
                        ? 'The room-change payment window expired. Your existing booking remains unchanged; contact hotel staff to request a new payment link.'
                        : 'The payment window has expired. Please create a new booking.',
                ];
            }

            $this->authorization->activate(
                $booking,
                $authorization['access_token'],
                (bool) $authorization['consume_bootstrap']
            );

            return [
                'booking' => $booking,
                'payment' => $payment->fresh('manualGcashSubmissions'),
                'access_token' => $authorization['access_token'],
            ];
        }, 3);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['message']], $result['error']);
        }

        $response = response()->json([
            'success' => true,
            'data' => $this->guestPayload($result['booking'], $result['payment']),
        ]);
        $response->headers->setCookie($this->authorization->accessCookie($request, $bookingId, $result['access_token']));
        $response->headers->setCookie($this->authorization->expiredBootstrapCookie($request, $bookingId));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function status(Request $request, int $bookingId)
    {
        if (! $this->authorization->validateStatus($request, $bookingId)) {
            return response()->json(['message' => 'Payment authorization is required.'], 401);
        }

        $booking = Booking::with(['primaryGuest', 'bookingRooms.room'])->find($bookingId);
        $payment = $booking?->payments()
            ->where('provider', PaymentProviderService::MANUAL_GCASH)
            ->latest()
            ->with('manualGcashSubmissions')
            ->first();

        if (! $booking || ! $payment) {
            return response()->json(['message' => 'Manual GCash payment not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->guestPayload($booking, $payment)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function qr(Request $request, int $bookingId): StreamedResponse
    {
        if (! $this->authorization->validateStatus($request, $bookingId)) {
            abort(401, 'Payment authorization is required.');
        }

        $paymentExists = Payment::query()
            ->where('booking_id', $bookingId)
            ->where('provider', PaymentProviderService::MANUAL_GCASH)
            ->exists();
        $configuration = $this->configuration->configuration();

        if (! $paymentExists || ! $configuration || $this->configuration->issues($configuration) !== []) {
            abort(404);
        }

        $stream = $this->configuration->qrStream($configuration);
        if (! is_resource($stream)) {
            abort(404);
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $configuration->qr_mime_type,
            'Content-Length' => (string) $configuration->qr_size,
            'Content-Disposition' => 'inline; filename="hotel-gcash-qr.'.pathinfo($configuration->qr_path, PATHINFO_EXTENSION).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function submit(Request $request, int $bookingId)
    {
        if (! $this->authorization->validateStatus($request, $bookingId)) {
            return response()->json(['message' => 'Payment authorization is required.'], 401);
        }

        $validated = $request->validate([
            'transaction_reference' => ['required', 'string', 'regex:/^[0-9]{13}$/D'],
            'sender_name' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'declaration_accepted' => ['accepted'],
            'proof' => ['required', 'file', 'max:'.max(1024, (int) config('payment.manual_gcash.max_proof_kilobytes', 5120))],
        ], [
            'transaction_reference.regex' => 'Enter the complete 13-digit GCash Transaction Reference ID.',
        ]);

        $normalizedReference = $this->normalizeReference($validated['transaction_reference']);
        if (! preg_match('/^[0-9]{13}$/D', $normalizedReference)) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'Enter the complete 13-digit GCash Transaction Reference ID.',
            ]);
        }

        $storedProof = $this->storeProof($request->file('proof'));

        try {
            $submission = DB::transaction(function () use ($bookingId, $validated, $normalizedReference, $storedProof) {
                $booking = Booking::whereKey($bookingId)->lockForUpdate()->firstOrFail();
                $payment = Payment::query()
                    ->where('booking_id', $bookingId)
                    ->where('provider', PaymentProviderService::MANUAL_GCASH)
                    ->latest()
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($payment->payment_status !== 'pending') {
                    throw ValidationException::withMessages(['proof' => 'This payment is no longer pending.']);
                }

                if (! $payment->payment_due_at || $payment->payment_due_at->isPast()) {
                    throw ValidationException::withMessages(['proof' => 'The payment proof submission window has expired.']);
                }

                if ($this->hasProtectedSubmission($payment)) {
                    throw ValidationException::withMessages(['proof' => 'A proof is already awaiting staff verification.']);
                }

                $maxAttempts = max(1, (int) config('payment.manual_gcash.max_submission_attempts', 3));
                if ((int) $payment->submission_attempts >= $maxAttempts) {
                    throw ValidationException::withMessages(['proof' => 'The maximum number of proof submissions has been reached.']);
                }

                if ((int) round((float) $validated['amount'] * 100) !== (int) round((float) $payment->amount * 100)) {
                    throw ValidationException::withMessages([
                        'amount' => 'The submitted amount must exactly match the required downpayment.',
                    ]);
                }

                $paidAt = Carbon::parse($validated['paid_at']);
                if ($paidAt->lt($booking->created_at->copy()->subMinutes(5))) {
                    throw ValidationException::withMessages([
                        'paid_at' => 'Payment time cannot be earlier than this booking.',
                    ]);
                }

                $now = now();
                $reviewDueAt = $now->copy()->addMinutes(max(5, (int) config('payment.manual_gcash.review_target_minutes', 15)));
                $escalationDueAt = $now->copy()->addMinutes(max(15, (int) config('payment.manual_gcash.review_hold_minutes', 120)));
                $attempt = (int) $payment->submission_attempts + 1;

                $submission = ManualGcashSubmission::create(array_merge($storedProof, [
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'attempt_number' => $attempt,
                    'transaction_reference' => trim($validated['transaction_reference']),
                    'normalized_transaction_reference' => $normalizedReference,
                    'active_reference_claim' => $normalizedReference,
                    'sender_name' => trim($validated['sender_name']),
                    'submitted_amount' => round((float) $validated['amount'], 2),
                    'paid_at' => $paidAt,
                    'status' => ManualGcashSubmission::STATUS_PENDING,
                    'declaration_accepted_at' => $now,
                    'submitted_at' => $now,
                    'review_due_at' => $reviewDueAt,
                    'escalation_due_at' => $escalationDueAt,
                ]));

                $payment->update([
                    'transaction_reference' => trim($validated['transaction_reference']),
                    'proof_submitted_at' => $now,
                    'review_due_at' => $reviewDueAt,
                    'review_hold_until' => $escalationDueAt,
                    'submission_attempts' => $attempt,
                    'lifecycle_status' => Payment::LIFECYCLE_PENDING_VERIFICATION,
                    'lifecycle_message' => 'Payment proof is awaiting verification against merchant GCash records.',
                ]);
                if ($payment->purpose !== Payment::PURPOSE_REBOOKING_ADJUSTMENT) {
                    $booking->update(['expires_at' => $escalationDueAt]);
                }
                $this->rebookingAdjustments->markProofSubmitted($payment, $escalationDueAt);

                return $submission->load(['booking.primaryGuest', 'payment']);
            }, 3);
        } catch (QueryException $exception) {
            Storage::disk($storedProof['proof_disk'])->delete($storedProof['proof_path']);
            if (str_contains(strtolower($exception->getMessage()), 'active_reference_claim')) {
                throw ValidationException::withMessages([
                    'transaction_reference' => 'This GCash transaction reference is already attached to another active payment review.',
                ]);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Storage::disk($storedProof['proof_disk'])->delete($storedProof['proof_path']);
            throw $exception;
        }

        NotificationHelper::paymentSubmitted([
            'id' => 'PAY'.str_pad((string) $submission->payment_id, 3, '0', STR_PAD_LEFT),
            'booking_id' => $submission->booking->reference_number,
            'guest' => $submission->booking->primaryGuest?->name ?? 'Guest',
            'amount' => 'PHP '.number_format((float) $submission->submitted_amount, 2),
            'method' => 'Manual GCash',
        ]);
        $this->queueStatusEmail($submission->booking, $submission, 'submitted');
        AuditHelper::log(
            actionActivity: 'Manual GCash Proof Submitted',
            modulePage: 'Payment Module',
            modelType: 'ManualGcashSubmission',
            modelId: $submission->id,
            recordAffected: 'Booking '.$submission->booking->reference_number,
            oldValues: null,
            newValues: ['attempt' => $submission->attempt_number, 'status' => $submission->status],
            action: 'created',
            actorLabel: 'Guest Portal'
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment proof submitted. Your booking is awaiting staff verification.',
            'data' => $this->guestPayload($submission->booking, $submission->payment->fresh('manualGcashSubmissions')),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    private function guestPayload(Booking $booking, Payment $payment): array
    {
        $configuration = $this->configuration->configuration();
        $latest = $payment->manualGcashSubmissions->sortByDesc('id')->first();

        return [
            'provider' => PaymentProviderService::MANUAL_GCASH,
            'booking_id' => $booking->id,
            'booking_reference' => $booking->reference_number,
            'booking_status' => $booking->booking_status,
            'payment_status' => $payment->payment_status,
            'lifecycle_status' => $payment->resolvedLifecycleStatus(),
            'lifecycle_message' => $payment->lifecycle_message,
            'required_amount' => (float) $payment->amount,
            'purpose' => $payment->purpose ?: Payment::PURPOSE_BOOKING,
            'is_rebooking_adjustment' => $payment->purpose === Payment::PURPOSE_REBOOKING_ADJUSTMENT,
            'rebooking_id' => $payment->rebooking_id,
            'payment_due_at' => $payment->payment_due_at?->toIso8601String(),
            'attempts_used' => (int) $payment->submission_attempts,
            'attempts_remaining' => max(0, max(1, (int) config('payment.manual_gcash.max_submission_attempts', 3)) - (int) $payment->submission_attempts),
            'merchant' => [
                'merchant_name' => $configuration?->merchant_name,
                'account_name' => $configuration?->account_name,
                'account_number' => $configuration?->account_number,
                'qr_url' => url('/api/client/manual-gcash/'.$booking->id.'/qr'),
            ],
            'latest_submission' => $latest ? [
                'id' => $latest->id,
                'attempt_number' => $latest->attempt_number,
                'status' => $latest->status,
                'transaction_reference' => $latest->transaction_reference,
                'submitted_amount' => (float) $latest->submitted_amount,
                'submitted_at' => $latest->submitted_at?->toIso8601String(),
                'review_reason' => $latest->review_reason,
            ] : null,
        ];
    }

    private function hasProtectedSubmission(Payment $payment): bool
    {
        return $payment->manualGcashSubmissions()
            ->whereIn('status', [ManualGcashSubmission::STATUS_PENDING, ManualGcashSubmission::STATUS_ESCALATED])
            ->exists();
    }

    private function normalizeReference(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($reference)) ?? '');
    }

    private function storeProof(?UploadedFile $file): array
    {
        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages(['proof' => 'Upload a valid payment proof file.']);
        }

        $realPath = $file->getRealPath();
        $detectedMime = $realPath ? (new \finfo(FILEINFO_MIME_TYPE))->file($realPath) : false;
        $extension = null;

        if (is_string($detectedMime) && isset(self::IMAGE_MIME_TYPES[$detectedMime])) {
            $image = @getimagesize($realPath);
            if (! $image || ($image['mime'] ?? null) !== $detectedMime) {
                throw ValidationException::withMessages(['proof' => 'The proof image content is invalid.']);
            }
            $extension = self::IMAGE_MIME_TYPES[$detectedMime];
        } elseif ($detectedMime === 'application/pdf') {
            $handle = fopen($realPath, 'rb');
            $signature = $handle ? fread($handle, 5) : '';
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($signature !== '%PDF-') {
                throw ValidationException::withMessages(['proof' => 'The proof PDF content is invalid.']);
            }
            $extension = 'pdf';
        } else {
            throw ValidationException::withMessages(['proof' => 'Proof must be a JPEG, PNG, WebP, or PDF file.']);
        }

        $disk = 'manual_gcash_proofs';
        $path = now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
        if ($stored !== $path || ! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('Payment proof could not be stored securely.');
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

    private function queueStatusEmail(Booking $booking, ?ManualGcashSubmission $submission, string $status, ?string $reason = null): void
    {
        $email = $booking->primaryGuest?->email;
        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->queue(new ManualGcashStatusMail($booking, $submission, $status, $reason));
        } catch (\Throwable $exception) {
            Log::error('Manual GCash status email could not be queued', [
                'booking_id' => $booking->id,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
