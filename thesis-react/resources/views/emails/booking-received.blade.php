@extends('emails.layouts.email')

@section('email_title', 'Booking Received')
@section('preheader', 'Your booking was received and is waiting for the required payment.')
@section('heading', 'Booking Created')
@section('subheading', 'Payment pending')

@section('content')
@php
    $totalAmount = (float) $booking->total_amount;
    $totalPaid = (float) $booking->payments->whereIn('payment_status', ['completed', 'verified'])->sum('amount');
    $remainingBalance = max(0, $totalAmount - $totalPaid);
    $pendingPayment = $booking->payments->where('payment_status', 'pending')->sortByDesc('created_at')->first();
    $downpaymentAmount = (float) ($pendingPayment->amount ?? 0);
@endphp
    <p class="mail-text">Dear {{ $booking->primaryGuest->name }},</p>
    <p class="mail-text">
        Your booking has been created. Please complete the required downpayment to confirm your reservation.
    </p>

    <div class="mail-section">
        <h2 class="mail-section-title">Booking Summary</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Reference Number</span><span class="mail-value">{{ $booking->reference_number }}</span></div>
            <div class="mail-row"><span class="mail-label">Check-in</span><span class="mail-value">{{ \Carbon\Carbon::parse($booking->check_in)->format('l, F j, Y') }}</span></div>
            <div class="mail-row"><span class="mail-label">Check-out</span><span class="mail-value">{{ \Carbon\Carbon::parse($booking->check_out)->format('l, F j, Y') }}</span></div>
            <div class="mail-row"><span class="mail-label">Total Amount</span><span class="mail-value">&#8369;{{ number_format($totalAmount, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Required Downpayment</span><span class="mail-value">&#8369;{{ number_format($downpaymentAmount, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Amount Paid</span><span class="mail-value">&#8369;{{ number_format($totalPaid, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Remaining Balance</span><span class="mail-value">&#8369;{{ number_format($remainingBalance, 2) }}</span></div>
        </div>
    </div>

    <p class="mail-note">
        Once your downpayment is received via GCash, your booking will be confirmed automatically. Remaining balance is payable at check-in.
    </p>
@endsection
