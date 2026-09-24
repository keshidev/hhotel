<?php

namespace App\Http\Controllers\Client;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\ContactInquiry;
use App\Services\ContactInquiryNotificationService;
use App\Services\PublicCaptchaService;
use App\Support\PhilippineMobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContactInquiryController extends Controller
{
    public function store(
        Request $request,
        PublicCaptchaService $captcha,
        ContactInquiryNotificationService $notifications
    ): JsonResponse {
        if ($request->has('phone')) {
            $request->merge(['phone' => PhilippineMobileNumber::normalize($request->input('phone'))]);
        }
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:80'],
            'last_name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+639[0-9]{9}$/D'],
            'booking_reference' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', Rule::in(['general', 'reservation', 'billing', 'feedback'])],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'website' => ['nullable', 'max:0'],
            'captcha_token' => ['nullable', 'string', 'max:4096'],
        ], [
            'website.max' => 'The inquiry could not be submitted.',
            'phone.regex' => 'Enter a Philippine mobile number: +63 followed by 10 digits starting with 9.',
        ]);

        $captcha->verify($validated['captcha_token'] ?? null, $request->ip());

        $email = strtolower(trim($validated['email']));
        $message = preg_replace('/\s+/u', ' ', trim($validated['message']));
        $windowSeconds = max(60, (int) config('contact.duplicate_window_minutes', 10) * 60);
        $bucket = (int) floor(now()->timestamp / $windowSeconds);
        $submissionKey = hash('sha256', implode('|', [$email, $validated['subject'], $message, $bucket]));

        $inquiry = ContactInquiry::firstOrCreate(
            ['submission_key' => $submissionKey],
            [
                'reference_number' => $this->newReference(),
                'first_name' => trim($validated['first_name']),
                'last_name' => trim($validated['last_name']),
                'email' => $email,
                'phone' => $this->nullableTrim($validated['phone'] ?? null),
                'booking_reference' => $this->nullableTrim($validated['booking_reference'] ?? null),
                'subject' => $validated['subject'],
                'message' => trim($validated['message']),
                'status' => 'new',
                'source_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            ]
        );

        if ($inquiry->wasRecentlyCreated) {
            AuditHelper::log(
                'Guest Inquiry Submitted',
                'Contact Inquiries',
                'ContactInquiry',
                $inquiry->id,
                $inquiry->reference_number,
                null,
                ['subject' => $inquiry->subject, 'status' => $inquiry->status],
                'created',
                actorLabel: 'Website Guest'
            );
        }

        if ($inquiry->wasRecentlyCreated
            || $inquiry->customer_email_status === 'failed'
            || $inquiry->staff_notification_status === 'failed') {
            try {
                $notifications->dispatch($inquiry);
            } catch (\Throwable $exception) {
                $inquiry->forceFill([
                    'customer_email_status' => $inquiry->customer_email_status === 'sent' ? 'sent' : 'failed',
                    'staff_notification_status' => $inquiry->staff_notification_status === 'sent' ? 'sent' : 'failed',
                    'notification_error' => $exception->getMessage(),
                ])->save();

                Log::error('Contact inquiry notification dispatch failed', [
                    'inquiry_id' => $inquiry->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'duplicate' => ! $inquiry->wasRecentlyCreated,
            'message' => $inquiry->wasRecentlyCreated
                ? 'Your message was received by the hotel team.'
                : 'We already received this message.',
            'data' => [
                'reference_number' => $inquiry->reference_number,
                'status' => $inquiry->status,
            ],
        ], $inquiry->wasRecentlyCreated ? 201 : 200);
    }

    private function newReference(): string
    {
        do {
            $reference = 'INQ'.Str::upper(Str::random(13));
        } while (ContactInquiry::where('reference_number', $reference)->exists());

        return $reference;
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
