@extends('emails.layouts.email')

@section('email_title', 'We Received Your Message')
@section('preheader', 'Your inquiry has been received by the H+ Hotel team.')
@section('heading', 'We Received Your Message')
@section('subheading', 'Guest inquiry acknowledgement')

@section('content')
    <p class="mail-text">Hello {{ $inquiry->first_name ?: 'Guest' }},</p>
    <p class="mail-text">Our hotel team has received your {{ str_replace('_', ' ', $inquiry->subject) }} inquiry.</p>

    <div class="mail-section">
        <h2 class="mail-section-title">Inquiry Details</h2>
        <div class="mail-panel">
            <div class="mail-row">
                <span class="mail-label">Inquiry Reference</span>
                <span class="mail-value mail-code">{{ $inquiry->reference_number }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Subject</span>
                <span class="mail-value">{{ ucfirst(str_replace('_', ' ', $inquiry->subject)) }}</span>
            </div>
        </div>
    </div>

    <p class="mail-note">Keep this reference if you contact the hotel about your message. Please do not send the same inquiry again unless your details change.</p>
@endsection
