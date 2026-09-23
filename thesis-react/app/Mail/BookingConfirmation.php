<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\CmsSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BookingConfirmation extends Mailable implements ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 60;
    public int $maxExceptions = 5;

    public function __construct(public Booking $booking)
    {

        if (!$this->booking->relationLoaded('primaryGuest')) {
            $this->booking->load('primaryGuest');
        }

        if (!$this->booking->relationLoaded('bookingRooms')) {
            $this->booking->load('bookingRooms.room');
        }

        if (!$this->booking->relationLoaded('payments')) {
            $this->booking->load('payments');
        }

        if (!$this->booking->relationLoaded('promoCode')) {
            $this->booking->load('promoCode');
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Confirmed - ' . $this->booking->reference_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmation',
            with: [
                'policySettings' => $this->resolvePolicySettings(),
                'policiesItems' => $this->resolvePoliciesItems(),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'Content-Type' => 'text/html; charset=UTF-8',
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

        Booking::query()
            ->whereKey($this->booking->id)
            ->whereNull('confirmation_email_sent_at')
            ->update([
                'confirmation_email_status' => 'queued',
                'confirmation_email_queued_at' => now(),
                'confirmation_email_failed_at' => null,
                'confirmation_email_last_error' => null,
            ]);

        return $queuedJob;
    }

    public function send($mailer)
    {
        Booking::query()->whereKey($this->booking->id)->increment(
            'confirmation_email_attempts',
            1,
            [
                'confirmation_email_status' => 'sending',
                'confirmation_email_last_error' => null,
            ]
        );

        try {
            $sentMessage = parent::send($mailer);
        } catch (Throwable $e) {
            Booking::query()->whereKey($this->booking->id)->update([
                'confirmation_email_status' => 'retrying',
                'confirmation_email_last_error' => Str::limit($e->getMessage(), 5000, ''),
            ]);

            Log::warning('Booking confirmation email delivery attempt failed', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Booking::query()->whereKey($this->booking->id)->update([
            'confirmation_email_status' => 'sent',
            'confirmation_email_sent_at' => now(),
            'confirmation_email_failed_at' => null,
            'confirmation_email_last_error' => null,
        ]);

        return $sentMessage;
    }

    public function failed(Throwable $e): void
    {
        Booking::query()->whereKey($this->booking->id)->update([
            'confirmation_email_status' => 'failed',
            'confirmation_email_failed_at' => now(),
            'confirmation_email_last_error' => Str::limit($e->getMessage(), 5000, ''),
        ]);

        Log::error('Booking confirmation email exhausted all retries', [
            'booking_id' => $this->booking->id,
            'attempts' => $this->tries,
            'error' => $e->getMessage(),
        ]);
    }

    private function resolvePolicySettings(): array
    {
        return CmsSetting::query()
            ->where('group', 'policies')
            ->whereNotIn('key', ['policies_items', 'policy_privacy_terms', 'policy_booking_conditions'])
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('id')
            ->get(['key', 'label', 'value'])
            ->map(fn ($item) => [
                'key' => (string) $item->key,
                'label' => (string) ($item->label ?: ucfirst(str_replace('_', ' ', (string) $item->key))),
                'value' => (string) $item->value,
            ])
            ->values()
            ->all();
    }

    private function resolvePoliciesItems(): array
    {
        $rawPoliciesItems = CmsSetting::query()
            ->where('group', 'policies')
            ->where('key', 'policies_items')
            ->value('value');

        if (is_array($rawPoliciesItems)) {
            $decodedItems = $rawPoliciesItems;
        } elseif (is_string($rawPoliciesItems) && trim($rawPoliciesItems) !== '') {
            $decoded = json_decode($rawPoliciesItems, true);
            $decodedItems = is_array($decoded) ? $decoded : [];
        } else {
            $decodedItems = [];
        }

        $normalizedItems = collect($decodedItems)
            ->filter(fn ($policy) => is_array($policy))
            ->map(function (array $policy) {
                $title = trim((string) ($policy['title'] ?? ''));
                $body = trim((string) ($policy['body'] ?? ''));

                return [
                    'title' => $title,
                    'body' => $body,
                ];
            })
            ->filter(fn (array $policy) => $policy['title'] !== '' || $policy['body'] !== '')
            ->values()
            ->all();

        if (!empty($normalizedItems)) {
            return $normalizedItems;
        }

        return collect($this->resolvePolicySettings())
            ->map(function (array $policy) {
                return [
                    'title' => trim((string) ($policy['label'] ?? $policy['key'] ?? 'Policy')),
                    'body' => trim((string) ($policy['value'] ?? '')),
                ];
            })
            ->filter(fn (array $policy) => $policy['title'] !== '' || $policy['body'] !== '')
            ->values()
            ->all();
    }
}
