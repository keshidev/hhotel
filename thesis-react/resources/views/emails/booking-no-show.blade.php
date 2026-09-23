@extends('emails.layouts.email')

@section('email_title', 'No-Show Notice')
@section('preheader', 'Your H+ Hotel booking has been marked as a no-show.')
@section('heading', 'Booking Marked as No-Show')
@section('subheading', 'Please review your reservation status')

@section('content')
@php
    $guestName = $booking->primaryGuest?->name ?? 'Guest';
    $canShowRoomNumber = (string) ($booking->room_assignment_status ?? 'pending_assignment') === 'assigned';
    $roomSummary = $booking->bookingRooms
        ->map(function ($line) use ($canShowRoomNumber) {
            $roomType = $line->room?->room_type ?? $line->requested_room_type;
            if (!$roomType) {
                return null;
            }

            $roomTypeLabel = ucfirst(str_replace('_', ' ', (string) $roomType));
            if ($canShowRoomNumber && !empty($line->room?->room_number)) {
                return $roomTypeLabel . ' - Room ' . $line->room->room_number;
            }

            return $roomTypeLabel;
        })
        ->filter()
        ->implode(', ');
@endphp

    <p class="mail-text">Dear {{ $guestName }},</p>
    <p class="mail-text">
        Your booking has been marked as no-show because our team did not receive your arrival at the property and could not reach you through your registered contact details.
    </p>

    <div class="mail-section">
        <h2 class="mail-section-title">Booking Details</h2>
        <div class="mail-panel">
            <div class="mail-row">
                <span class="mail-label">Reference Number</span>
                <span class="mail-value mail-code">{{ $booking->reference_number }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Guest Name</span>
                <span class="mail-value">{{ $guestName }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Reserved Room</span>
                <span class="mail-value">{{ $roomSummary ?: 'N/A' }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Original Check-In</span>
                <span class="mail-value">{{ optional($booking->check_in)->format('F j, Y') }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Original Check-Out</span>
                <span class="mail-value">{{ optional($booking->check_out)->format('F j, Y') }}</span>
            </div>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">No-Show Policy</h2>
        <div class="mail-panel">
            <p class="mail-note">{{ $noShowPolicy }}</p>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Need Help?</h2>
        <div class="mail-panel">
            <p class="mail-note">If you believe this is an error, please contact us immediately.</p>
            <p class="mail-note">Phone: {{ $contactInfo['phone'] }}</p>
            <p class="mail-note">Email: {{ $contactInfo['email'] }}</p>
            <p class="mail-note">Address: {{ $contactInfo['address'] }}</p>
        </div>
    </div>
@endsection
