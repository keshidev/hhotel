<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>@yield('email_title', config('app.name'))</title>
    <style>
        html, body { margin:0 !important; padding:0 !important; width:100% !important; background:#eef2f7; }
        body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
        table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
        table { border-collapse:collapse !important; border-spacing:0 !important; }
        img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
        a { color:#0b3a82; }
        .mail-preview { display:none !important; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all; color:transparent; }
        .mail-page { width:100%; background:#eef2f7; }
        .mail-page-cell { padding:32px 16px; }
        .mail-card { width:100%; max-width:600px; background:#ffffff; border:1px solid #d8e1ee; border-radius:16px; overflow:hidden; }
        .mail-header { background:#071a3d; color:#ffffff; padding:26px 28px 24px; }
        .mail-logo { display:block; width:158px; max-width:100%; height:auto; margin:0 0 18px; }
        .mail-heading { margin:0; color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:1.25; font-weight:700; }
        .mail-subheading { margin:7px 0 0; color:#dbe7fb; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.5; }
        .mail-body { padding:28px; color:#1e293b; font-family:Arial,Helvetica,sans-serif; }
        .mail-footer { background:#f8fafc; border-top:1px solid #d8e1ee; padding:20px 28px; }
        .mail-footer-line { margin:0; color:#64748b; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.65; overflow-wrap:anywhere; word-break:break-word; }
        .mail-footer-line + .mail-footer-line { margin-top:3px; }
        .mail-muted { color:#64748b; }
        .mail-section { margin:0 0 22px; }
        .mail-section:last-child { margin-bottom:0; }
        .mail-section-title { margin:0 0 10px; color:#475569; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.4; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; }
        .mail-panel { background:#f8fafc; border:1px solid #d8e1ee; border-radius:12px; padding:15px 16px; }
        .mail-row { display:table; table-layout:fixed; width:100%; padding:0; border-bottom:1px solid #e3eaf3; }
        .mail-row:last-child { border-bottom:none; }
        .mail-label, .mail-value { display:table-cell; width:50%; padding:9px 0; vertical-align:top; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:1.5; overflow-wrap:anywhere; word-break:break-word; }
        .mail-label { padding-right:10px; color:#64748b; }
        .mail-value { padding-left:10px; color:#0f172a; font-weight:600; text-align:right; }
        .mail-text { margin:0 0 14px; color:#1e293b; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.65; overflow-wrap:anywhere; word-break:break-word; }
        .mail-note { margin:0; color:#475569; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:1.65; overflow-wrap:anywhere; word-break:break-word; }
        .mail-note + .mail-note { margin-top:6px; }
        .mail-button { display:inline-block; box-sizing:border-box; min-height:46px; padding:13px 20px; background:#0b3a82; border:1px solid #0b3a82; border-radius:9px; color:#ffffff !important; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; font-weight:700; letter-spacing:0.04em; text-align:center; text-decoration:none; text-transform:uppercase; }
        .mail-code { color:#0f172a; font-family:'Courier New',monospace; font-size:12px; line-height:1.6; overflow-wrap:anywhere; word-break:break-all; }
        .mail-badge { display:inline-block; padding:5px 10px; border-radius:999px; font-family:Arial,Helvetica,sans-serif; font-size:11px; line-height:1.4; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; }
        .mail-badge-success { background:#dcfce7; color:#166534; }
        .mail-badge-warning { background:#fef3c7; color:#92400e; }
        .mail-badge-danger { background:#fee2e2; color:#991b1b; }
        .mail-divider { height:1px; margin:14px 0; border:0; background:#d8e1ee; }
        @yield('extra_styles')
        @media only screen and (max-width: 480px) {
            .mail-page-cell { padding:12px !important; }
            .mail-card { border-radius:12px !important; }
            .mail-header { padding:21px 20px 20px !important; }
            .mail-logo { width:140px !important; margin-bottom:16px !important; }
            .mail-heading { font-size:22px !important; line-height:1.3 !important; }
            .mail-subheading { font-size:13px !important; }
            .mail-body { padding:20px !important; }
            .mail-footer { padding:18px 20px !important; }
            .mail-section { margin-bottom:20px !important; }
            .mail-panel { padding:12px 14px !important; }
            .mail-row { display:block !important; }
            .mail-label, .mail-value { display:block !important; box-sizing:border-box !important; width:100% !important; padding-left:0 !important; padding-right:0 !important; text-align:left !important; }
            .mail-label { padding-top:9px !important; padding-bottom:2px !important; }
            .mail-value { padding-top:0 !important; padding-bottom:9px !important; }
            .mail-button { display:block !important; width:100% !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#eef2f7;">
@php
    $brandBaseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
    $logoUrl = $brandBaseUrl.'/images/logo/bw_logo.png';
@endphp
    <div class="mail-preview">@yield('preheader', 'An update from H+ Hotel')</div>
    <table role="presentation" class="mail-page" width="100%" cellpadding="0" cellspacing="0" style="width:100%; background:#eef2f7;">
        <tr>
            <td class="mail-page-cell" align="center" style="padding:32px 16px;">
                <table role="presentation" class="mail-card" width="100%" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; background:#ffffff; border:1px solid #d8e1ee; border-radius:16px; overflow:hidden;">
                    <tr>
                        <td class="mail-header" style="background:#071a3d; color:#ffffff; padding:26px 28px 24px;">
                            <img src="{{ $logoUrl }}" width="158" alt="H+ Hotel" class="mail-logo" style="display:block; width:158px; max-width:100%; height:auto; margin:0 0 18px;">
                            <h1 class="mail-heading">@yield('heading', config('app.name'))</h1>
                            @hasSection('subheading')
                                <p class="mail-subheading">@yield('subheading')</p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="mail-body" style="padding:28px; color:#1e293b; font-family:Arial,Helvetica,sans-serif;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td class="mail-footer" style="background:#f8fafc; border-top:1px solid #d8e1ee; padding:20px 28px;">
                            <p class="mail-footer-line">H+ Hotel &bull; One Nenita Place, 89 Road 1, Bagong Pag-asa, Quezon City</p>
                            <p class="mail-footer-line">+63 917 809 9482 &bull; {{ config('mail.from.address') }}</p>
                            <p class="mail-footer-line">This is an automated email from H+ Hotel. Please do not reply directly.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
