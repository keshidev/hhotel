<?php

namespace App\Mail;

use App\Models\ContactInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactInquiryStaffNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactInquiry $inquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Guest Inquiry] '.$this->inquiry->reference_number.' - '.ucfirst($this->inquiry->subject));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-inquiry-staff');
    }
}
