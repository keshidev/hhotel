@extends('emails.layouts.email')

@section('email_title', 'Password Changed')
@section('preheader', 'Your H+ Hotel staff account password was changed.')
@section('heading', 'Your Password Was Changed')
@section('subheading', 'Account security confirmation')

@section('content')
    <p class="mail-text">Hello, {{ $userName }}</p>
    <p class="mail-text">The password for your H+ Hotel staff account was changed successfully.</p>

    <div class="mail-section">
        <div class="mail-panel">
            <p class="mail-note"><strong>All existing sessions were signed out.</strong> Use your new password the next time you log in.</p>
        </div>
    </div>

    <div class="mail-section">
        <div class="mail-panel">
            <p class="mail-note"><strong>Did not make this change?</strong> Contact the hotel administrator immediately so the account can be secured.</p>
        </div>
    </div>
@endsection
