@extends('emails.layouts.email')

@section('email_title', 'Email Delivery Test')
@section('preheader', 'The H+ Hotel email delivery test completed successfully.')
@section('heading', 'Email delivery is working')
@section('subheading', 'Administrator diagnostics')

@section('content')
    <p class="mail-text">
        Hello {{ $administratorName }},
    </p>
    <p class="mail-text">
        This test message confirms that the H+ Hotel system can deliver email using its current server configuration.
    </p>

    <div class="mail-section">
        <h2 class="mail-section-title">Test Details</h2>
        <div class="mail-panel">
            <div class="mail-row">
                <span class="mail-label">Environment</span>
                <span class="mail-value">{{ ucfirst($environment) }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Sent at</span>
                <span class="mail-value">{{ $sentAt }}</span>
            </div>
        </div>
    </div>

    <p class="mail-note">
        No reply is required. This message was requested from the administrator settings page.
    </p>
@endsection
