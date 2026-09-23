<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingWorkflowDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $heading,
        public string $status,
        public string $messageBody,
        public ?string $decisionNote = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->heading} - {$this->booking->reference_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-workflow-decision',
        );
    }
}
