<?php

namespace App\Jobs;

use App\Mail\ContactInquiryResolution;
use App\Models\ContactInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendContactInquiryResolution implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $inquiryId, public int $resolutionVersion) {}

    public function handle(): void
    {
        $claimed = ContactInquiry::query()
            ->whereKey($this->inquiryId)
            ->where('status', 'resolved')
            ->where('resolution_email_version', $this->resolutionVersion)
            ->whereIn('resolution_email_status', ['pending', 'failed'])
            ->update([
                'resolution_email_status' => 'sending',
                'resolution_email_error' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $inquiry = ContactInquiry::find($this->inquiryId);
        if (! $inquiry || ! $inquiry->email || $inquiry->anonymized_at) {
            ContactInquiry::query()
                ->whereKey($this->inquiryId)
                ->where('resolution_email_version', $this->resolutionVersion)
                ->update(['resolution_email_status' => 'not_applicable']);
            return;
        }

        try {
            Mail::to($inquiry->email)->send(new ContactInquiryResolution($inquiry));

            ContactInquiry::query()
                ->whereKey($this->inquiryId)
                ->where('resolution_email_version', $this->resolutionVersion)
                ->update([
                    'resolution_email_status' => 'sent',
                    'resolution_email_sent_at' => now(),
                    'resolution_email_error' => null,
                ]);
        } catch (Throwable $exception) {
            ContactInquiry::query()
                ->whereKey($this->inquiryId)
                ->where('resolution_email_version', $this->resolutionVersion)
                ->update([
                    'resolution_email_status' => 'failed',
                    'resolution_email_error' => Str::limit($exception->getMessage(), 2000, ''),
                ]);

            Log::error('Contact inquiry resolution email failed', [
                'inquiry_id' => $this->inquiryId,
                'resolution_version' => $this->resolutionVersion,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
