<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\CmsSetting;
use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class NoShowNotification extends Mailable implements ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 60;
    public int $maxExceptions = 5;

    public function __construct(public Booking $booking)
    {
        $this->booking->loadMissing(['primaryGuest', 'bookingRooms.room']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Booking Has Been Marked as No-Show - ' . $this->booking->reference_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-no-show',
            with: [
                'noShowPolicy' => $this->resolveNoShowPolicy(),
                'contactInfo' => $this->resolveContactInfo(),
            ],
        );
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function queue(Queue $queue)
    {
        $queuedJob = parent::queue($queue);

        Booking::query()->whereKey($this->booking->id)->whereNull('no_show_email_sent_at')->update([
            'no_show_email_status' => 'queued',
            'no_show_email_queued_at' => now(),
            'no_show_email_failed_at' => null,
            'no_show_email_last_error' => null,
        ]);

        return $queuedJob;
    }

    public function send($mailer)
    {
        Booking::query()->whereKey($this->booking->id)->increment('no_show_email_attempts', 1, [
            'no_show_email_status' => 'sending',
            'no_show_email_last_error' => null,
        ]);

        try {
            $sentMessage = parent::send($mailer);
        } catch (Throwable $e) {
            Booking::query()->whereKey($this->booking->id)->update([
                'no_show_email_status' => 'retrying',
                'no_show_email_last_error' => Str::limit($e->getMessage(), 5000, ''),
            ]);

            Log::warning('No-show email delivery attempt failed', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Booking::query()->whereKey($this->booking->id)->update([
            'no_show_email_status' => 'sent',
            'no_show_email_sent_at' => now(),
            'no_show_email_failed_at' => null,
            'no_show_email_last_error' => null,
        ]);

        return $sentMessage;
    }

    public function failed(Throwable $e): void
    {
        Booking::query()->whereKey($this->booking->id)->update([
            'no_show_email_status' => 'failed',
            'no_show_email_failed_at' => now(),
            'no_show_email_last_error' => Str::limit($e->getMessage(), 5000, ''),
        ]);

        Log::error('No-show email exhausted all retries', [
            'booking_id' => $this->booking->id,
            'attempts' => $this->tries,
            'error' => $e->getMessage(),
        ]);
    }

    private function resolveNoShowPolicy(): string
    {
        $cmsPolicy = CmsSetting::query()
            ->where('group', 'policies')
            ->where(function ($query) {
                $query->where('key', 'policy_no_show')
                    ->orWhere('key', 'no_show_policy')
                    ->orWhere('label', 'like', '%No-Show%');
            })
            ->value('value');

        if (is_string($cmsPolicy) && trim($cmsPolicy) !== '') {
            return trim($cmsPolicy);
        }

        $systemPolicy = SystemSetting::read('no_show_policy', null);
        if (is_string($systemPolicy) && trim($systemPolicy) !== '') {
            return trim($systemPolicy);
        }

        return 'No-show bookings may be charged according to hotel policy. Please contact the front desk for assistance.';
    }

    private function resolveContactInfo(): array
    {
        $settings = SystemSetting::readMany([
            'contact_phone' => '',
            'contact_email' => (string) config('mail.from.address', ''),
            'address' => 'One Nenita Place 89 Road 1 Bagong Pagasa, Quezon City',
        ]);

        return [
            'phone' => (string) ($settings['contact_phone'] ?: '+63 917 809 9482'),
            'email' => (string) ($settings['contact_email'] ?: config('mail.from.address')),
            'address' => (string) ($settings['address'] ?: 'One Nenita Place 89 Road 1 Bagong Pagasa, Quezon City'),
        ];
    }
}
