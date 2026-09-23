<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestFeedbackRequest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Booking  $booking,
        public readonly Feedback $feedback,
        public readonly string   $feedbackUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'How was your stay? - H+ Hotel',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.feedback-request');
    }
}
