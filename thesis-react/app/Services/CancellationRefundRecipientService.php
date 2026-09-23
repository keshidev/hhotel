<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CancellationApprovalRequest;
use App\Models\ManualGcashSubmission;
use Illuminate\Support\Facades\Validator;

class CancellationRefundRecipientService
{
    public function normalizeAccount(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value ?? '');
        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }
        return preg_match('/^09\d{9}$/D', $digits) ? $digits : null;
    }

    public function context(Booking $booking): array
    {
        $booking->loadMissing(['primaryGuest', 'payments.manualGcashSubmissions']);
        $payments = $booking->payments->filter(fn ($payment) => $payment->payment_status === 'completed' && $payment->payment_type !== 'refund');
        $manual = $payments->where('provider', 'manual_gcash');
        $senders = $manual->flatMap(fn ($payment) => $payment->manualGcashSubmissions)
            ->where('status', ManualGcashSubmission::STATUS_APPROVED)
            ->pluck('sender_name')->map(fn ($name) => trim((string) $name))->filter()->unique()->values();
        $remaining = max(0, (float) $payments->sum('amount') - (float) $booking->payments->where('payment_type', 'refund')->sum('amount'));

        return [
            'required' => $manual->isNotEmpty() && $remaining > 0.00001
                && ! app(BookingCancellationService::class)->isNonRefundableNow($booking),
            'name' => $senders->count() === 1 ? $senders->first() : ($booking->primaryGuest?->name ?? ''),
            'nameSource' => $senders->count() === 1 ? 'approved_payment_sender' : 'booking_guest',
            'guestContactNumber' => $this->normalizeAccount($booking->primaryGuest?->phone),
        ];
    }

    public function confirmedDetails(array $details): array
    {
        Validator::make($details, [
            'refund_recipient_name' => 'required|string|max:120',
            'refund_recipient_account' => 'required|string|max:32',
        ])->validate();
        $details['refund_recipient_name'] = trim((string) ($details['refund_recipient_name'] ?? ''));
        $details['refund_recipient_account'] = $this->normalizeAccount($details['refund_recipient_account'] ?? null);
        $validated = Validator::make($details, [
            'refund_recipient_name' => 'required|string|min:2|max:120',
            'refund_recipient_account' => ['required', 'regex:/^09\d{9}$/D'],
            'refund_recipient_confirmed' => 'required|accepted',
        ], [
            'refund_recipient_name.required' => 'Enter the GCash refund recipient name.',
            'refund_recipient_account.required' => 'Enter a valid Philippine GCash refund number.',
            'refund_recipient_confirmed.required' => 'Confirm the refund recipient details before submitting.',
            'refund_recipient_confirmed.accepted' => 'Confirm the refund recipient details before submitting.',
        ])->validate();

        return [
            'refund_recipient_name' => $validated['refund_recipient_name'],
            'refund_recipient_account' => $validated['refund_recipient_account'],
            'refund_recipient_confirmed_at' => now(),
        ];
    }

    public function adminSuggestion(CancellationApprovalRequest $request): array
    {
        $context = $this->context($request->booking);
        $confirmed = $request->refund_recipient_confirmed_at !== null;
        return [
            'name' => $confirmed ? $request->refund_recipient_name : $context['name'],
            'account' => $confirmed ? $request->refund_recipient_account : '',
            'source' => $confirmed ? 'guest_confirmed' : $context['nameSource'],
            'confirmedAt' => $request->refund_recipient_confirmed_at?->toIso8601String(),
            'guestContactNumber' => $context['guestContactNumber'],
        ];
    }
}
