<?php

namespace App\Services;

use App\Helpers\NotificationHelper;
use App\Jobs\SendContactInquiryNotifications;
use App\Models\ContactInquiry;
use App\Models\Notification;
use App\Models\User;

class ContactInquiryNotificationService
{
    public function dispatch(ContactInquiry $inquiry): void
    {
        $metadata = [
            'inquiry_id' => $inquiry->id,
            'reference_number' => $inquiry->reference_number,
            'subject' => $inquiry->subject,
        ];

        User::active()->whereIn('role', ['admin', 'receptionist'])->pluck('id')->each(function ($userId) use ($inquiry, $metadata) {
            $message = "Inquiry {$inquiry->reference_number} requires staff review.";

            if (! Notification::where('user_id', $userId)
                ->where('type', 'contact_inquiry')
                ->where('message', $message)
                ->exists()) {
                NotificationHelper::send(
                    (int) $userId,
                    'contact_inquiry',
                    'New Guest Inquiry',
                    $message,
                    $metadata
                );
            }
        });

        SendContactInquiryNotifications::dispatch($inquiry->id)->afterCommit();
    }
}
