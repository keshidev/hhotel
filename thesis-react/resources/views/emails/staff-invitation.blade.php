@extends('emails.layouts.email')

@section('email_title', 'Set Up Your Staff Account')
@section('preheader', 'An administrator created your H+ Hotel staff account. Set your own password to sign in.')
@section('heading', 'Your Staff Account Is Ready')
@section('subheading', 'Secure account setup')

@section('content')
    <p class="mail-text">Hello, {{ $userName }}</p>
    <p class="mail-text">An administrator created a {{ ucfirst($role) }} account for you at H+ Hotel. Use the secure link below to set your own password. The administrator does not know your password.</p>

    <div class="mail-section">
        <a href="{{ $setupLink }}" class="mail-button">Set My Password</a>
    </div>

    <div class="mail-section">
        <div class="mail-panel">
            <p class="mail-note">This link expires in <strong>{{ $expiresIn }}</strong> and can be used once. If it expires, use Forgot Password on the staff login page to request a new link.</p>
        </div>
    </div>

    <p class="mail-note">If you were not expecting a staff account, contact the hotel administrator. Never share this link or your password.</p>
    <p class="mail-note">If the button does not work, copy this URL into your browser:</p>
    <p class="mail-note"><a href="{{ $setupLink }}">{{ $setupLink }}</a></p>
@endsection
