@extends('emails.layouts.email')

@section('email_title', 'Additional Payment Required')
@section('preheader', 'Complete the additional verified GCash payment for your requested room change.')
@section('heading', 'Additional Payment Required')
@section('subheading', $booking->reference_number)

@section('content')
    <p class="mail-text">Dear {{ $booking->primaryGuest?->name ?? 'Guest' }},</p>
    <p class="mail-text">Your requested room change has a higher required downpayment. Your current booking remains confirmed and unchanged while the request is pending.</p>

    <div class="mail-section">
        <h2 class="mail-section-title">Payment Details</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Request</span><span class="mail-value">RBK{{ str_pad((string) $rebooking->id, 3, '0', STR_PAD_LEFT) }}</span></div>
            <div class="mail-row"><span class="mail-label">Additional Payment</span><span class="mail-value">PHP {{ number_format((float) $payment->amount, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Payment Deadline</span><span class="mail-value">{{ $payment->payment_due_at?->format('M j, Y g:i A') }}</span></div>
        </div>
    </div>

    <div class="mail-section" style="text-align:center;">
        <a href="{{ $paymentUrl }}" class="mail-button">Pay Additional Amount</a>
    </div>
    <p class="mail-note">This secure link can be used only for this booking and expires at the deadline above. The room change will require final staff approval after payment verification.</p>
@endsection
