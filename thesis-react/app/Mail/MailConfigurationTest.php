<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MailConfigurationTest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $administratorName,
        public string $environment,
        public string $sentAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'H+ Hotel email delivery test',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mail-configuration-test',
        );
    }
}
