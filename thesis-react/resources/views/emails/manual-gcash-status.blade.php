@extends('emails.layouts.email')

@section('email_title', 'Manual GCash Payment Update')
@section('preheader', 'An update about the GCash payment proof for your booking.')
@section('heading', 'Manual GCash Payment Update')
@section('subheading', $booking->reference_number)

@section('content')
    @php
        $isRebooking = $submission?->payment?->purpose === \App\Models\Payment::PURPOSE_REBOOKING_ADJUSTMENT;
        $title = match ($status) {
            'submitted' => 'Proof received - awaiting verification',
            'rejected' => $canRetry ? 'Proof needs correction' : ($isRebooking ? 'Proof rejected - room change remains pending' : 'Proof rejected - booking closed'),
            'expired' => 'Payment window expired',
            default => 'Payment update',
        };
    @endphp

    <p class="mail-text">Dear {{ $booking->primaryGuest?->name ?? 'Guest' }},</p>
    <p class="mail-text"><strong>{{ $title }}</strong></p>

    @if ($status === 'submitted')
        <p class="mail-text">We received your proof. A hotel staff member must verify it against the official merchant GCash records before {{ $isRebooking ? 'your room change can receive final approval' : 'your booking can be confirmed' }}.</p>
    @elseif ($status === 'rejected' && $canRetry)
        <p class="mail-text">The submitted proof could not be approved. Your booking is still open, and you may submit one corrected proof before the deadline below.</p>
    @elseif ($status === 'rejected')
        <p class="mail-text">The submitted proof could not be approved, and the payment window is now closed. {{ $isRebooking ? 'Your existing booking remains confirmed and unchanged. Hotel staff may issue a new payment request after reviewing the room change.' : 'This booking was not confirmed and cannot accept another proof.' }}</p>
    @else
        <p class="mail-text">{{ $isRebooking ? 'The room change was not finalized because a valid additional payment proof was not approved within the allowed window. Your existing booking remains unchanged.' : 'The booking was not confirmed because a valid payment proof was not approved within the allowed payment window.' }}</p>
    @endif

    <div class="mail-section">
        <h2 class="mail-section-title">Payment Details</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Booking Reference</span><span class="mail-value">{{ $booking->reference_number }}</span></div>
            @if ($submission)
                <div class="mail-row"><span class="mail-label">GCash Reference</span><span class="mail-value">{{ $submission->transaction_reference }}</span></div>
                <div class="mail-row"><span class="mail-label">Submitted Amount</span><span class="mail-value">PHP {{ number_format((float) $submission->submitted_amount, 2) }}</span></div>
            @endif
            @if ($status === 'rejected' && $canRetry)
                <div class="mail-row"><span class="mail-label">Correction Deadline</span><span class="mail-value">{{ $paymentDueAt }}</span></div>
                <div class="mail-row"><span class="mail-label">Attempts Remaining</span><span class="mail-value">{{ $attemptsRemaining }}</span></div>
            @endif
        </div>
    </div>

    @if ($reason)
        <div class="mail-section">
            <h2 class="mail-section-title">Reason</h2>
            <div class="mail-panel"><p class="mail-note">{{ $reason }}</p></div>
        </div>
    @endif

    @if ($status === 'rejected' && $canRetry && $resumeUrl)
        <div class="mail-section" style="text-align:center;">
            <a href="{{ $resumeUrl }}" class="mail-button">Correct Payment Proof</a>
        </div>
        <p class="mail-note">If the button does not open, copy this secure link into your browser:</p>
        <p class="mail-code" style="word-break:break-all;">{{ $resumeUrl }}</p>
    @endif

    <p class="mail-note">A screenshot or customer claim does not confirm a booking. Do not pay again. If correction is allowed, only replace the proof using the secure page.</p>
@endsection
