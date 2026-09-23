@extends('emails.layouts.email')

@section('email_title', 'Reset Your Password')
@section('preheader', 'Use this secure link to reset your H+ Hotel staff password.')
@section('heading', 'Password Reset Request')
@section('subheading', 'Account security update')

@section('content')
    <p class="mail-text">Hello, {{ $userName }}</p>
    <p class="mail-text">We received a request to reset your password. Click the button below to set a new password.</p>

    <div class="mail-section">
        <a href="{{ $resetLink }}" class="mail-button">Reset My Password</a>
    </div>

    <div class="mail-section">
        <div class="mail-panel">
            <p class="mail-note">
                This link will expire in <strong>{{ $expiresIn }}</strong>. If you did not request a password reset, you can safely ignore this email.
            </p>
        </div>
    </div>

    <div class="mail-section">
        <div class="mail-panel">
            <p class="mail-note"><strong>Security Notice:</strong> Never share this link with anyone. Our staff will never ask for your password or reset link.</p>
        </div>
    </div>

    <p class="mail-note">If the button above doesn't work, copy and paste this URL into your browser:</p>
    <p class="mail-note"><a href="{{ $resetLink }}">{{ $resetLink }}</a></p>
@endsection
