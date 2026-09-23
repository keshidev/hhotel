<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmailDesignConsistencyTest extends TestCase
{
    public function test_every_system_email_uses_the_shared_mobile_first_design(): void
    {
        $templates = [
            'admin-new-booking',
            'booking-confirmation',
            'booking-no-show',
            'booking-received',
            'booking-rejected',
            'booking-workflow-decision',
            'contact-inquiry-acknowledgement',
            'contact-inquiry-resolution',
            'contact-inquiry-staff',
            'feedback-request',
            'mail-configuration-test',
            'manual-gcash-status',
            'password-changed',
            'password-reset',
        ];

        foreach ($templates as $template) {
            $source = file_get_contents(resource_path("views/emails/{$template}.blade.php"));

            $this->assertStringContainsString("@extends('emails.layouts.email')", $source, $template);
            $this->assertStringContainsString("@section('preheader'", $source, $template);
            $this->assertStringNotContainsString('<!doctype html>', strtolower($source), $template);
        }

        $layout = file_get_contents(resource_path('views/emails/layouts/email.blade.php'));

        $this->assertStringContainsString("config('app.frontend_url', config('app.url'))", $layout);
        $this->assertStringContainsString("'/images/logo/bw_logo.png'", $layout);
        $this->assertStringContainsString('@media only screen and (max-width: 480px)', $layout);
        $this->assertStringContainsString('.mail-button { display:block !important; width:100% !important; }', $layout);
        $this->assertStringContainsString('overflow-wrap:anywhere', $layout);
        $this->assertStringNotContainsString('display:flex', $layout);
    }
}
