@extends('emails.layouts.email')

@section('email_title', 'New Booking Notification')
@section('preheader', 'A verified booking is ready for hotel preparation.')
@section('heading', 'New Booking')
@section('subheading', 'Admin notification')

@section('content')
@php
    $totalAmount = (float) $booking->total_amount;
    $totalPaid = (float) $booking->payments->whereIn('payment_status', ['completed', 'verified'])->sum('amount');
    $remainingBalance = max(0, $totalAmount - $totalPaid);
    $downpayment = $booking->payments->where('payment_type', 'downpayment')->sortByDesc('created_at')->first();
    $downpaymentAmount = (float) ($downpayment->amount ?? 0);
@endphp
    <p class="mail-text">A GCash downpayment was verified and the booking is now confirmed.</p>
    <p class="mail-text"><span class="mail-badge mail-badge-success">Payment Verified</span></p>

    <div class="mail-section">
        <h2 class="mail-section-title">Booking Summary</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Reference Number</span><span class="mail-value">{{ $booking->reference_number }}</span></div>
            <div class="mail-row"><span class="mail-label">Guest Name</span><span class="mail-value">{{ $booking->primaryGuest->name }}</span></div>
            <div class="mail-row"><span class="mail-label">Guest Email</span><span class="mail-value">{{ $booking->primaryGuest->email }}</span></div>
            <div class="mail-row"><span class="mail-label">Guest Phone</span><span class="mail-value">{{ $booking->primaryGuest->phone }}</span></div>
            <div class="mail-row"><span class="mail-label">Total Amount</span><span class="mail-value">&#8369;{{ number_format($totalAmount, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Required Downpayment</span><span class="mail-value">&#8369;{{ number_format($downpaymentAmount, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Amount Paid</span><span class="mail-value">&#8369;{{ number_format($totalPaid, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Remaining Balance</span><span class="mail-value">&#8369;{{ number_format($remainingBalance, 2) }}</span></div>
        </div>
    </div>

    <p class="mail-note">
        This booking is ready for hotel preparation. The remaining balance is collected at check-in.
    </p>
@endsection
