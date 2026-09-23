<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FrontDeskGcashPaymentService
{
    public function recordApprovedSubmission(
        Payment $payment,
        Booking $booking,
        User $staff,
        string $reference,
        string $senderName,
        Carbon $paidAt
    ): ManualGcashSubmission {
        $normalizedReference = $this->normalizeReference($reference);
        if (strlen($normalizedReference) < 6) {
            throw ValidationException::withMessages([
                'gcash_reference' => 'Enter a valid GCash reference number with at least 6 letters or numbers.',
            ]);
        }

        if (ManualGcashSubmission::where('active_reference_claim', $normalizedReference)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'gcash_reference' => 'This GCash reference number is already attached to another payment.',
            ]);
        }

        $recordedAt = now();
        $evidence = [
            'type' => 'front_desk_merchant_record_attestation',
            'booking_id' => $booking->id,
            'booking_reference' => $booking->reference_number,
            'payment_id' => $payment->id,
            'transaction_reference' => trim($reference),
            'sender_name' => trim($senderName),
            'amount' => (float) $payment->amount,
            'paid_at' => $paidAt->toIso8601String(),
            'recorded_by' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => $staff->role,
            ],
            'recorded_at' => $recordedAt->toIso8601String(),
            'attestation' => 'Staff matched this transaction against the official hotel GCash merchant record.',
        ];
        $contents = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = 'front-desk/'.now()->format('Y/m').'/'.Str::uuid().'.json';
        $disk = Storage::disk('manual_gcash_proofs');
        $disk->put($path, $contents);

        try {
            $submission = ManualGcashSubmission::create([
                'payment_id' => $payment->id,
                'booking_id' => $booking->id,
                'attempt_number' => 1,
                'submission_source' => ManualGcashSubmission::SOURCE_FRONT_DESK,
                'transaction_reference' => trim($reference),
                'normalized_transaction_reference' => $normalizedReference,
                'active_reference_claim' => $normalizedReference,
                'sender_name' => trim($senderName),
                'submitted_amount' => $payment->amount,
                'paid_at' => $paidAt,
                'status' => ManualGcashSubmission::STATUS_APPROVED,
                'declaration_accepted_at' => $recordedAt,
                'proof_disk' => 'manual_gcash_proofs',
                'proof_path' => $path,
                'proof_original_name' => 'front-desk-gcash-attestation.json',
                'proof_mime_type' => 'application/json',
                'proof_size' => strlen($contents),
                'proof_sha256' => hash('sha256', $contents),
                'proof_retention_expires_at' => $recordedAt->copy()->addDays(
                    max(30, (int) config('payment.manual_gcash.proof_retention_days', 180))
                ),
                'submitted_at' => $recordedAt,
                'review_due_at' => $recordedAt,
                'escalation_due_at' => $recordedAt,
                'reviewed_by' => $staff->id,
                'reviewed_at' => $recordedAt,
                'review_reason' => 'Verified at front desk against the official hotel GCash merchant record.',
                'admin_override' => false,
            ]);

            $payment->forceFill([
                'submission_attempts' => 1,
                'proof_submitted_at' => $recordedAt,
                'review_due_at' => $recordedAt,
            ])->save();

            return $submission;
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public function deleteEvidence(?ManualGcashSubmission $submission): void
    {
        if (! $submission?->proof_path) {
            return;
        }

        Storage::disk($submission->proof_disk ?: 'manual_gcash_proofs')->delete($submission->proof_path);
    }

    public function normalizeReference(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($reference)) ?? '');
    }
}
