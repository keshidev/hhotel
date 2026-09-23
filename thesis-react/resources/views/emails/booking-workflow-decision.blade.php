@extends('emails.layouts.email')

@section('email_title', $heading)
@section('preheader', 'An update about your H+ Hotel booking request.')
@section('heading', $heading)
@section('subheading', 'Booking workflow update')

@section('content')
    <p class="mail-text">
        Hello {{ $booking->primaryGuest?->name ?? 'Guest' }},
    </p>
    <p class="mail-text">
        {{ $messageBody }}
    </p>

    <div class="mail-section">
        <h2 class="mail-section-title">Booking Summary</h2>
        <div class="mail-panel">
            <div class="mail-row">
                <span class="mail-label">Reference</span>
                <span class="mail-value">{{ $booking->reference_number }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Check-in</span>
                <span class="mail-value">{{ optional($booking->check_in)->format('Y-m-d') }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Check-out</span>
                <span class="mail-value">{{ optional($booking->check_out)->format('Y-m-d') }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Status</span>
                <span class="mail-value">{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
            </div>
        </div>
    </div>

    @if($decisionNote)
        <div class="mail-section">
            <h2 class="mail-section-title">Staff Note</h2>
            <div class="mail-panel">
                <p class="mail-note">{{ $decisionNote }}</p>
            </div>
        </div>
    @endif

    <p class="mail-note">
        If you need help, please reply to this email or contact the front desk.
    </p>
@endsection
