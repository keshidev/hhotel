@extends('emails.layouts.email')

@section('email_title', 'How was your stay?')
@section('preheader', 'Tell us about your recent stay at H+ Hotel.')
@section('heading', 'We Would Love Your Feedback')
@section('subheading', 'Share your recent stay experience')

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
    <p class="mail-text">Dear {{ $booking->primaryGuest?->name ?? 'Valued Guest' }},</p>
    <p class="mail-text">
        Thank you for choosing H+ Hotel. We hope your stay was comfortable and memorable.
        We'd be grateful if you could take a moment to share your experience. Your feedback directly helps us improve and better serve our guests.
    </p>

    <div class="mail-section">
        <h2 class="mail-section-title">Booking Summary</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Guest</span><span class="mail-value">{{ $booking->primaryGuest?->name ?? 'Guest' }}</span></div>
            <div class="mail-row"><span class="mail-label">Room</span><span class="mail-value">{{ $roomSummary ?: 'N/A' }}</span></div>
            <div class="mail-row"><span class="mail-label">Stay</span><span class="mail-value">{{ \Carbon\Carbon::parse($booking->check_in)->format('M d') }} - {{ \Carbon\Carbon::parse($booking->check_out)->format('M d, Y') }}</span></div>
        </div>
    </div>

    <div class="mail-section">
        <a href="{{ $feedbackUrl }}" class="mail-button">Leave Your Feedback</a>
    </div>

    <p class="mail-note">This link can only be used once.</p>
    <p class="mail-note">If the button above doesn't work, copy and paste this link into your browser:</p>
    <p class="mail-note"><a href="{{ $feedbackUrl }}">{{ $feedbackUrl }}</a></p>
@endsection
