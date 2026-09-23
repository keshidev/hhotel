<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Rebooking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RebookingAdditionalPaymentRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public Rebooking $rebooking,
        public Payment $payment,
        public string $paymentUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Additional Payment Required for Room Change - '.$this->booking->reference_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rebooking-additional-payment');
    }
}
