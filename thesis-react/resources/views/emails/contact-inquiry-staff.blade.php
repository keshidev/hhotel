@extends('emails.layouts.email')

@section('email_title', 'New Guest Inquiry')
@section('preheader', 'A new guest inquiry is waiting for staff review.')
@section('heading', 'New Guest Inquiry')
@section('subheading', 'Staff notification')

@section('content')
    <p class="mail-text">A new guest inquiry is ready for assignment and review.</p>

    <div class="mail-section">
        <h2 class="mail-section-title">Guest and Inquiry Details</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Reference</span><span class="mail-value mail-code">{{ $inquiry->reference_number }}</span></div>
            <div class="mail-row"><span class="mail-label">Guest</span><span class="mail-value">{{ $inquiry->guest_name }}</span></div>
            <div class="mail-row"><span class="mail-label">Email</span><span class="mail-value">{{ $inquiry->email }}</span></div>
            <div class="mail-row"><span class="mail-label">Phone</span><span class="mail-value">{{ $inquiry->phone ?: 'Not provided' }}</span></div>
            <div class="mail-row"><span class="mail-label">Booking Reference</span><span class="mail-value">{{ $inquiry->booking_reference ?: 'Not provided' }}</span></div>
            <div class="mail-row"><span class="mail-label">Subject</span><span class="mail-value">{{ ucfirst(str_replace('_', ' ', $inquiry->subject)) }}</span></div>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Guest Message</h2>
        <div class="mail-panel">
            <p class="mail-note" style="white-space:pre-wrap;">{{ $inquiry->message }}</p>
        </div>
    </div>

    <p class="mail-note">Open Guest Inquiries in the staff panel to assign and resolve this message.</p>
@endsection
