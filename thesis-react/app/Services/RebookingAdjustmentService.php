<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Mail\RebookingAdditionalPaymentRequested;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Rebooking;
use App\Models\RebookingRefund;
use App\Models\RebookingRoomHold;
use App\Models\User;
use App\Support\PhilippineMobileNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RebookingAdjustmentService
{
    public function __construct(
        private RebookingRequestService $rebookings,
        private PaymentAccessSessionService $paymentAccessSessions,
        private PaymentProviderService $paymentProvider
    ) {}

    public function requestAdditionalPayment(int $requestId, User $actor): array
    {
        if (! $this->paymentProvider->uses(PaymentProviderService::MANUAL_GCASH)
            || $this->paymentProvider->operationalIssues() !== []) {
            throw new \RuntimeException('PAYMENT_SERVICE_UNAVAILABLE');
        }

        $result = DB::transaction(function () use ($requestId, $actor) {
            [$leader, $rows, $booking] = $this->lockedContext($requestId);
            if ($rows->contains(fn (Rebooking $row) =>
                ! in_array($row->status, [Rebooking::STATUS_PENDING, Rebooking::STATUS_AWAITING_PAYMENT], true)
                || $row->finalized_at
            )) {
                throw new \RuntimeException('INVALID_STATUS');
            }

            $existingPayment = $leader->adjustmentPayment()->lockForUpdate()->first();
            if ($existingPayment?->payment_status === 'pending') {
                $protectedProof = $existingPayment->manualGcashSubmissions()
                    ->whereIn('status', ['pending_verification', 'escalated'])
                    ->exists();
                if ($protectedProof || $existingPayment->payment_due_at?->isFuture()) {
                    throw new \RuntimeException('PAYMENT_REQUEST_ALREADY_ACTIVE');
                }
                $existingPayment->update([
                    'payment_status' => 'failed',
                    'lifecycle_status' => Payment::LIFECYCLE_EXPIRED,
                    'lifecycle_message' => 'The rebooking payment window expired before verified proof was received.',
                ]);
                RebookingRoomHold::whereIn('rebooking_id', $rows->pluck('id'))->whereNull('released_at')->update([
                    'released_at' => now(), 'updated_at' => now(),
                ]);
                Rebooking::whereIn('id', $rows->pluck('id'))->update([
                    'status' => Rebooking::STATUS_PENDING,
                    'adjustment_payment_id' => null,
                    'quote_expires_at' => null,
                    'updated_at' => now(),
                ]);
                $leader = $leader->fresh();
                $rows = $this->groupRows($leader, true);
            }

            $assessment = $this->rebookings->financialAssessment($leader);
            if (($assessment['status'] ?? null) !== Rebooking::FINANCIAL_ADDITIONAL_PAYMENT_REQUIRED) {
                throw new \RuntimeException('ADDITIONAL_PAYMENT_NOT_REQUIRED');
            }

            $this->assertRequestedRoomsStillAvailable($rows, $booking);
            $amount = round((float) $assessment['amount'], 2);
            $dueAt = now()->addMinutes(max(5, (int) config('payment.manual_gcash.payment_window_minutes', 30)));
            $payment = Payment::create([
                'booking_id' => $booking->id,
                'rebooking_id' => $leader->id,
                'amount' => $amount,
                'payment_type' => Payment::TYPE_BALANCE_PAYMENT,
                'purpose' => Payment::PURPOSE_REBOOKING_ADJUSTMENT,
                'payment_method' => 'gcash',
                'payment_status' => 'pending',
                'lifecycle_status' => Payment::LIFECYCLE_AWAITING_PAYMENT,
                'lifecycle_message' => 'Additional rebooking payment is awaiting guest proof.',
                'payment_due_at' => $dueAt,
                'provider' => PaymentProviderService::MANUAL_GCASH,
                'notes' => 'Additional verified downpayment required for rebooking request RBK'.str_pad((string) $leader->id, 3, '0', STR_PAD_LEFT).'.',
            ]);

            $token = Str::random(64);
            $booking->forceFill([
                'payment_bootstrap_token_hash' => hash('sha256', $token),
                'payment_bootstrap_expires_at' => $dueAt,
            ])->save();
            $this->paymentAccessSessions->revoke($booking->id, 'rebooking_adjustment_requested');

            foreach ($rows as $row) {
                $row->update([
                    'status' => Rebooking::STATUS_AWAITING_PAYMENT,
                    'financial_status' => Rebooking::FINANCIAL_AWAITING_PAYMENT,
                    'original_total' => $assessment['original_total'],
                    'projected_total' => $assessment['projected_total'],
                    'price_difference' => $assessment['price_difference'],
                    'adjustment_amount' => $amount,
                    'adjustment_payment_id' => $row->id === $leader->id ? $payment->id : null,
                    'quote_expires_at' => $dueAt,
                ]);
                RebookingRoomHold::updateOrCreate(
                    ['rebooking_id' => $row->id],
                    [
                        'booking_id' => $booking->id,
                        'room_id' => $row->requested_room_id,
                        'check_in' => $booking->check_in,
                        'check_out' => $booking->check_out,
                        'expires_at' => $dueAt,
                        'released_at' => null,
                    ]
                );
            }

            AuditHelper::log(
                actionActivity: 'Rebooking Additional Payment Requested',
                modulePage: 'Rebooking Module',
                modelType: 'Rebooking',
                modelId: $leader->id,
                recordAffected: 'Booking '.$booking->reference_number,
                oldValues: ['status' => Rebooking::STATUS_PENDING],
                newValues: ['status' => Rebooking::STATUS_AWAITING_PAYMENT, 'amount' => $amount, 'payment_id' => $payment->id, 'due_at' => $dueAt->toDateTimeString()],
                action: 'updated',
                actorUser: $actor
            );

            return compact('leader', 'booking', 'payment', 'token', 'dueAt', 'assessment');
        }, 3);

        $paymentUrl = route('manual-gcash.resume', ['token' => $result['token']]);
        if ($result['booking']->primaryGuest?->email) {
            try {
                Mail::to($result['booking']->primaryGuest->email)->queue(new RebookingAdditionalPaymentRequested(
                    $result['booking']->fresh('primaryGuest'),
                    $result['leader'],
                    $result['payment'],
                    $paymentUrl
                ));
            } catch (\Throwable $exception) {
                Log::error('Rebooking additional payment email could not be queued', [
                    'rebooking_id' => $result['leader']->id,
                    'payment_id' => $result['payment']->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'rebooking' => $result['leader']->fresh(),
            'payment' => $result['payment']->fresh(),
            'payment_url' => $paymentUrl,
            'assessment' => $result['assessment'],
        ];
    }

    public function sendForRefundReview(int $requestId, User $actor): Rebooking
    {
        return DB::transaction(function () use ($requestId, $actor) {
            [$leader, $rows, $booking] = $this->lockedContext($requestId);
            $assessment = $this->rebookings->financialAssessment($leader);
            if (($assessment['status'] ?? null) !== Rebooking::FINANCIAL_REFUND_REVIEW) {
                throw new \RuntimeException('REFUND_NOT_REQUIRED');
            }

            $this->assertRequestedRoomsStillAvailable($rows, $booking);
            foreach ($rows as $row) {
                $row->update([
                    'status' => Rebooking::STATUS_UNDER_REVIEW,
                    'financial_status' => Rebooking::FINANCIAL_REFUND_REVIEW,
                    'original_total' => $assessment['original_total'],
                    'projected_total' => $assessment['projected_total'],
                    'price_difference' => $assessment['price_difference'],
                    'adjustment_amount' => $assessment['amount'],
                    'quote_expires_at' => null,
                ]);
                RebookingRoomHold::updateOrCreate(
                    ['rebooking_id' => $row->id],
                    [
                        'booking_id' => $booking->id,
                        'room_id' => $row->requested_room_id,
                        'check_in' => $booking->check_in,
                        'check_out' => $booking->check_out,
                        'expires_at' => null,
                        'released_at' => null,
                    ]
                );
            }

            AuditHelper::log(
                actionActivity: 'Rebooking Sent for Refund Review',
                modulePage: 'Rebooking Module',
                modelType: 'Rebooking',
                modelId: $leader->id,
                recordAffected: 'Booking '.$booking->reference_number,
                oldValues: ['status' => $leader->status],
                newValues: ['status' => Rebooking::STATUS_UNDER_REVIEW, 'refund_amount' => $assessment['amount']],
                action: 'updated',
                actorUser: $actor
            );

            return $leader->fresh();
        }, 3);
    }

    public function markProofSubmitted(Payment $payment, Carbon $holdUntil): void
    {
        if ($payment->purpose !== Payment::PURPOSE_REBOOKING_ADJUSTMENT || ! $payment->rebooking_id) {
            return;
        }

        $leader = Rebooking::find($payment->rebooking_id);
        if (! $leader) return;
        $rows = $this->groupRows($leader);
        Rebooking::whereIn('id', $rows->pluck('id'))->update([
            'financial_status' => Rebooking::FINANCIAL_PAYMENT_UNDER_REVIEW,
            'quote_expires_at' => $holdUntil,
            'updated_at' => now(),
        ]);
        RebookingRoomHold::whereIn('rebooking_id', $rows->pluck('id'))->update([
            'expires_at' => $holdUntil,
            'updated_at' => now(),
        ]);
    }

    public function markPaymentVerified(Payment $payment): void
    {
        if ($payment->purpose !== Payment::PURPOSE_REBOOKING_ADJUSTMENT || ! $payment->rebooking_id) return;
        $leader = Rebooking::find($payment->rebooking_id);
        if (! $leader) return;
        $rows = $this->groupRows($leader);
        Rebooking::whereIn('id', $rows->pluck('id'))->update([
            'status' => Rebooking::STATUS_UNDER_REVIEW,
            'financial_status' => Rebooking::FINANCIAL_READY,
            'quote_expires_at' => null,
            'updated_at' => now(),
        ]);
        RebookingRoomHold::whereIn('rebooking_id', $rows->pluck('id'))->update([
            'expires_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function markPaymentClosed(Payment $payment): void
    {
        if ($payment->purpose !== Payment::PURPOSE_REBOOKING_ADJUSTMENT || ! $payment->rebooking_id) return;
        $leader = Rebooking::find($payment->rebooking_id);
        if (! $leader) return;
        $rows = $this->groupRows($leader);
        Rebooking::whereIn('id', $rows->pluck('id'))->update([
            'status' => Rebooking::STATUS_PENDING,
            'financial_status' => Rebooking::FINANCIAL_ADDITIONAL_PAYMENT_REQUIRED,
            'adjustment_payment_id' => null,
            'quote_expires_at' => null,
            'updated_at' => now(),
        ]);
        RebookingRoomHold::whereIn('rebooking_id', $rows->pluck('id'))->whereNull('released_at')->update([
            'released_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function processRefundAndFinalize(int $requestId, User $admin, array $details): Rebooking
    {
        $leader = DB::transaction(function () use ($requestId, $admin, $details) {
            [$leader, $rows, $booking] = $this->lockedContext($requestId);
            if ($leader->status !== Rebooking::STATUS_UNDER_REVIEW || $leader->financial_status !== Rebooking::FINANCIAL_REFUND_REVIEW) {
                throw new \RuntimeException('INVALID_STATUS');
            }
            $assessment = $this->rebookings->financialAssessment($leader);
            if (($assessment['status'] ?? null) !== Rebooking::FINANCIAL_REFUND_REVIEW) {
                throw new \RuntimeException('REFUND_NOT_REQUIRED');
            }

            $this->assertRequestedRoomsStillAvailable($rows, $booking);
            $amount = round((float) $assessment['amount'], 2);
            $recipientName = trim((string) ($details['recipient_name'] ?? ''));
            $recipientAccount = PhilippineMobileNumber::normalize($details['recipient_account'] ?? '');
            $reference = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim((string) ($details['gcash_reference'] ?? ''))) ?? '');
            $reason = trim((string) ($details['refund_reason'] ?? ''));
            if ($recipientName === '') throw new \RuntimeException('REFUND_RECIPIENT_REQUIRED');
            if (! is_string($recipientAccount) || ! preg_match('/^\+639[0-9]{9}$/D', $recipientAccount)) throw new \RuntimeException('REFUND_RECIPIENT_ACCOUNT_INVALID');
            if (strlen($reference) < 6) throw new \RuntimeException('REFUND_REFERENCE_INVALID');
            if (mb_strlen($reason) < 10) throw new \RuntimeException('REFUND_REASON_REQUIRED');
            if (! ($details['manual_transfer_confirmed'] ?? false)) throw new \RuntimeException('MANUAL_TRANSFER_CONFIRMATION_REQUIRED');
            foreach (['proof_disk','proof_path','proof_original_name','proof_mime_type','proof_size','proof_sha256'] as $key) {
                if (empty($details['proof'][$key])) throw new \RuntimeException('REFUND_PROOF_REQUIRED');
            }
            try { $processedAt = Carbon::parse((string) ($details['processed_at'] ?? '')); }
            catch (\Throwable) { throw new \RuntimeException('REFUND_PROCESSED_AT_INVALID'); }
            if ($processedAt->isFuture()) throw new \RuntimeException('REFUND_PROCESSED_AT_INVALID');
            if (RebookingRefund::where('normalized_gcash_reference', $reference)->exists()) throw new \RuntimeException('REFUND_REFERENCE_DUPLICATE');

            $source = $booking->payments()->where('payment_type', '!=', Payment::TYPE_REFUND)
                ->where('payment_status', 'completed')->orderByDesc('paid_at')->lockForUpdate()->first();
            if (! $source || $amount <= 0) throw new \RuntimeException('REFUND_AMOUNT_INVALID');

            $refundPayment = Payment::create([
                'booking_id' => $booking->id,
                'rebooking_id' => $leader->id,
                'amount' => $amount,
                'payment_type' => Payment::TYPE_REFUND,
                'purpose' => Payment::PURPOSE_REBOOKING_ADJUSTMENT,
                'payment_method' => 'gcash',
                'payment_status' => 'refunded',
                'provider' => 'manual_gcash',
                'provider_reference' => $reference,
                'paid_at' => $processedAt,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'notes' => 'Rebooking price adjustment refund for RBK'.str_pad((string) $leader->id, 3, '0', STR_PAD_LEFT).'.',
            ]);
            RebookingRefund::create([
                'rebooking_id' => $leader->id,
                'booking_id' => $booking->id,
                'source_payment_id' => $source->id,
                'refund_payment_id' => $refundPayment->id,
                'amount' => $amount,
                'recipient_name' => $recipientName,
                'recipient_account' => $recipientAccount,
                'recipient_account_last_four' => substr($recipientAccount, -4),
                'gcash_reference' => trim((string) $details['gcash_reference']),
                'normalized_gcash_reference' => $reference,
                'processed_at' => $processedAt,
                'processed_by' => $admin->id,
                'reason' => $reason,
                ...$details['proof'],
                'proof_retention_expires_at' => $processedAt->copy()->addDays(max(30, (int) config('payment.manual_gcash.proof_retention_days', 180))),
            ]);
            Rebooking::whereIn('id', $rows->pluck('id'))->update([
                'financial_status' => Rebooking::FINANCIAL_REFUND_COMPLETED,
                'refund_processed_at' => $processedAt,
                'updated_at' => now(),
            ]);

            AuditHelper::log(
                actionActivity: 'Rebooking Refund Recorded', modulePage: 'Rebooking Module', modelType: 'Rebooking',
                modelId: $leader->id, recordAffected: 'Booking '.$booking->reference_number,
                oldValues: ['financial_status' => Rebooking::FINANCIAL_REFUND_REVIEW],
                newValues: ['financial_status' => Rebooking::FINANCIAL_REFUND_COMPLETED, 'amount' => $amount, 'refund_payment_id' => $refundPayment->id],
                action: 'updated', actorUser: $admin
            );
            return $leader->fresh();
        }, 3);

        // The transfer has already happened outside this application. Keep its
        // record committed if approval fails; the controller preserves the proof
        // and exposes recovery through the ordinary approval action.
        return $this->rebookings->approve($leader->id, $admin, 'Refund evidence recorded and rebooking finalized.');
    }

    private function lockedContext(int $requestId): array
    {
        $selected = Rebooking::query()->lockForUpdate()->findOrFail($requestId);
        $rows = $this->groupRows($selected, true);
        $leader = $rows->firstWhere('is_group_leader', true) ?? $rows->first();
        $booking = Booking::with(['primaryGuest', 'bookingRooms.room', 'payments'])->lockForUpdate()->findOrFail($leader->original_booking_id);
        return [$leader, $rows, $booking];
    }

    private function groupRows(Rebooking $selected, bool $lock = false)
    {
        $groupId = (int) ($selected->rebooking_group_id ?: $selected->id);
        $query = Rebooking::query()->where('original_booking_id', $selected->original_booking_id)
            ->where(fn ($q) => $q->where('rebooking_group_id', $groupId)->orWhere('id', $groupId))->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return $query->get();
    }

    private function assertRequestedRoomsStillAvailable($rows, Booking $booking): void
    {
        foreach ($rows as $row) {
            $hasOverlap = DB::table('booking_rooms')->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
                ->where('booking_rooms.room_id', $row->requested_room_id)->where('bookings.id', '!=', $booking->id)
                ->whereIn('bookings.booking_status', ['confirmed', 'checked_in'])
                ->where('bookings.check_in', '<', $booking->check_out)
                ->whereRaw('COALESCE(booking_rooms.extended_checkout, bookings.check_out) > ?', [$booking->check_in])->exists();
            $hasOtherHold = RebookingRoomHold::query()->where('room_id', $row->requested_room_id)
                ->where('booking_id', '!=', $booking->id)->whereNull('released_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->where('check_in', '<', $booking->check_out)->where('check_out', '>', $booking->check_in)->exists();
            if ($hasOverlap || $hasOtherHold) throw new \RuntimeException('REQUESTED_ROOM_NOT_AVAILABLE');
        }
    }
}
