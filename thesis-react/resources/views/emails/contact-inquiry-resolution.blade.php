@extends('emails.layouts.email')

@section('email_title', 'Your Inquiry Has Been Addressed')
@section('preheader', 'The H+ Hotel team has completed its review of your inquiry.')
@section('heading', 'Your Concern Has Been Addressed')
@section('subheading', 'Guest inquiry update')

@section('content')
    <p class="mail-text">Hello {{ $inquiry->first_name ?: 'Guest' }},</p>
    <p class="mail-text">Our hotel team has completed its review of your inquiry.</p>

    @if ($inquiry->resolution_message)
        <div class="mail-section">
            <h2 class="mail-section-title">Hotel Response</h2>
            <div class="mail-panel">
                <p class="mail-note" style="white-space:pre-wrap;">{{ $inquiry->resolution_message }}</p>
            </div>
        </div>
    @endif

    <div class="mail-section">
        <h2 class="mail-section-title">Inquiry Details</h2>
        <div class="mail-panel">
            <div class="mail-row">
                <span class="mail-label">Inquiry Reference</span>
                <span class="mail-value mail-code">{{ $inquiry->reference_number }}</span>
            </div>
        </div>
    </div>

    <p class="mail-note">Please keep this reference if you need to contact the hotel again about the same concern.</p>
@endsection
