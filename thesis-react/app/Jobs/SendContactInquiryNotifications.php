<?php

namespace App\Jobs;

use App\Mail\ContactInquiryAcknowledgement;
use App\Mail\ContactInquiryStaffNotification;
use App\Models\ContactInquiry;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendContactInquiryNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $inquiryId) {}

    public function handle(): void
    {
        $inquiry = ContactInquiry::find($this->inquiryId);
        if (! $inquiry || $inquiry->anonymized_at) {
            return;
        }

        $errors = [];

        try {
            if ($inquiry->customer_email_status !== 'sent' && $inquiry->email) {
                Mail::to($inquiry->email)->send(new ContactInquiryAcknowledgement($inquiry));
                $inquiry->forceFill([
                    'customer_email_status' => 'sent',
                    'customer_email_sent_at' => now(),
                ])->save();
            }
        } catch (Throwable $exception) {
            $errors[] = 'customer: '.$exception->getMessage();
            $inquiry->forceFill(['customer_email_status' => 'failed'])->save();
            Log::error('Contact inquiry acknowledgement failed', ['inquiry_id' => $inquiry->id, 'error' => $exception->getMessage()]);
        }

        try {
            if ($inquiry->staff_notification_status !== 'sent') {
                $staff = User::active()->whereIn('role', ['admin', 'receptionist'])->get();
                if ($staff->isNotEmpty()) {
                    $message = Mail::to($staff->first()->email);
                    $bcc = $staff->skip(1)->pluck('email')->filter()->values()->all();
                    if ($bcc !== []) {
                        $message->bcc($bcc);
                    }
                    $message->send(new ContactInquiryStaffNotification($inquiry));
                }
                $inquiry->forceFill([
                    'staff_notification_status' => 'sent',
                    'staff_notification_sent_at' => now(),
                ])->save();
            }
        } catch (Throwable $exception) {
            $errors[] = 'staff: '.$exception->getMessage();
            $inquiry->forceFill(['staff_notification_status' => 'failed'])->save();
            Log::error('Contact inquiry staff email failed', ['inquiry_id' => $inquiry->id, 'error' => $exception->getMessage()]);
        }

        $inquiry->forceFill(['notification_error' => $errors === [] ? null : implode(' | ', $errors)])->save();

        if ($errors !== []) {
            throw new \RuntimeException('One or more contact inquiry notifications failed.');
        }
    }
}
