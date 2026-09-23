@extends('emails.layouts.email')

@section('email_title', 'Booking Update')
@section('preheader', 'An important update about your H+ Hotel booking.')
@section('heading', 'Booking Update')
@section('subheading', 'Reservation not confirmed')

@section('content')
    @php
        $canShowRoomNumber = (string) ($booking->room_assignment_status ?? 'pending_assignment') === 'assigned';
        $roomSummary = $booking->bookingRooms->map(function ($line) use ($canShowRoomNumber) {
            $roomType = $line->room?->room_type ?? $line->requested_room_type;
            if (!$roomType) {
                return null;
            }

            $roomTypeLabel = ucfirst(str_replace('_', ' ', (string) $roomType));
            if ($canShowRoomNumber && !empty($line->room?->room_number)) {
                return $roomTypeLabel . ' - Room ' . $line->room->room_number;
            }

            return $roomTypeLabel;
        })->filter()->implode(', ');
    @endphp
    <p class="mail-text">Dear {{ $booking->primaryGuest->name }},</p>
    <p class="mail-text">We regret to inform you that we were unable to process your booking at this time.</p>
    <p class="mail-text"><span class="mail-badge mail-badge-danger">Not Confirmed</span></p>

    <div class="mail-section">
        <h2 class="mail-section-title">Booking Summary</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Reference Number</span><span class="mail-value">{{ $booking->reference_number }}</span></div>
            <div class="mail-row"><span class="mail-label">Check-in</span><span class="mail-value">{{ \Carbon\Carbon::parse($booking->check_in)->format('l, F j, Y') }}</span></div>
            <div class="mail-row"><span class="mail-label">Check-out</span><span class="mail-value">{{ \Carbon\Carbon::parse($booking->check_out)->format('l, F j, Y') }}</span></div>
            <div class="mail-row"><span class="mail-label">Room(s)</span><span class="mail-value">{{ $roomSummary ?: 'N/A' }}</span></div>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Reason</h2>
        <div class="mail-panel">
            <p class="mail-note">{{ $reason }}</p>
        </div>
    </div>

    <p class="mail-note">
        If you believe this is an error or would like to make a new booking, please contact us directly or visit our website.
        We apologize for any inconvenience.
    </p>
@endsection
