<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\ManualGcashSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ManualGcashStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public ?ManualGcashSubmission $submission,
        public string $status,
        public ?string $reason = null,
        public bool $canRetry = false,
        public ?string $resumeUrl = null,
        public ?string $paymentDueAt = null,
        public int $attemptsRemaining = 0
    ) {}

    public function envelope(): Envelope
    {
        $isRebooking = $this->submission?->payment?->purpose === \App\Models\Payment::PURPOSE_REBOOKING_ADJUSTMENT;
        $label = match ($this->status) {
            'submitted' => 'Payment Proof Received',
            'rejected' => $this->canRetry
                ? 'Payment Proof Needs Correction'
                : ($isRebooking ? 'Rebooking Payment Proof Rejected' : 'Payment Proof Rejected - Booking Closed'),
            'expired' => 'Payment Window Expired',
            default => 'Manual GCash Payment Update',
        };

        return new Envelope(subject: ($isRebooking ? 'Room Change: ' : '').$label.' - '.$this->booking->reference_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.manual-gcash-status');
    }
}
