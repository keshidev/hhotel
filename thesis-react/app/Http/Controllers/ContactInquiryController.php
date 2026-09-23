<?php

namespace App\Http\Controllers;

use App\Helpers\AuditHelper;
use App\Jobs\SendContactInquiryResolution;
use App\Models\ContactInquiry;
use App\Models\ContactInquiryStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContactInquiryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(['all'], ContactInquiry::STATUSES))],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = ContactInquiry::query()->with('assignee:id,name');
        if (($validated['status'] ?? 'all') !== 'all') {
            $query->where('status', $validated['status']);
        }
        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('booking_reference', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", ["%{$search}%"]);
            });
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'new' => ContactInquiry::where('status', 'new')->count(),
                'in_progress' => ContactInquiry::where('status', 'in_progress')->count(),
                'resolved' => ContactInquiry::where('status', 'resolved')->count(),
                'spam' => ContactInquiry::where('status', 'spam')->count(),
            ],
            'data' => $query->latest()->paginate((int) ($validated['per_page'] ?? 20)),
        ]);
    }

    public function show(ContactInquiry $contactInquiry): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $contactInquiry->load([
                'assignee:id,name',
                'statusHistory.actor:id,name',
            ]),
        ]);
    }

    public function updateStatus(Request $request, ContactInquiry $contactInquiry): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(ContactInquiry::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
            'customer_response' => ['exclude_unless:status,resolved', 'nullable', 'string', 'max:2000'],
        ]);

        $updated = DB::transaction(function () use ($contactInquiry, $validated, $request) {
            $inquiry = ContactInquiry::whereKey($contactInquiry->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $inquiry->status;
            $newStatus = $validated['status'];
            $reason = trim((string) ($validated['reason'] ?? ''));
            $customerResponse = trim((string) ($validated['customer_response'] ?? ''));
            $allowed = [
                'new' => ['in_progress', 'spam'],
                'in_progress' => ['resolved', 'spam'],
                'resolved' => ['in_progress'],
                'spam' => ['new'],
            ];

            if ($newStatus === $oldStatus) {
                abort(422, 'This inquiry already has that status.');
            }

            if (! in_array($newStatus, $allowed[$oldStatus] ?? [], true)) {
                abort(422, 'This inquiry status change is not allowed.');
            }

            $isSpamRestore = $oldStatus === 'spam' && $newStatus === 'new';
            if ($isSpamRestore && $request->user()->role !== 'admin') {
                abort(403, 'Only an administrator may restore a spam inquiry.');
            }

            $requiresReason = $newStatus === 'spam'
                || ($oldStatus === 'resolved' && $newStatus === 'in_progress')
                || $isSpamRestore;
            if ($requiresReason && $reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Please provide a reason for this status change.',
                ]);
            }

            $updates = [
                'status' => $newStatus,
                'assigned_to' => $newStatus === 'new' ? null : $request->user()->id,
                'resolved_at' => in_array($newStatus, ['resolved', 'spam'], true) ? now() : null,
            ];

            if ($newStatus === 'resolved') {
                $updates += [
                    'resolution_message' => $customerResponse !== '' ? $customerResponse : null,
                    'resolution_email_status' => $inquiry->email && ! $inquiry->anonymized_at ? 'pending' : 'not_applicable',
                    'resolution_email_version' => ((int) $inquiry->resolution_email_version) + 1,
                    'resolution_email_sent_at' => null,
                    'resolution_email_error' => null,
                ];
            }

            $inquiry->forceFill($updates)->save();

            ContactInquiryStatusHistory::create([
                'contact_inquiry_id' => $inquiry->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'reason' => $reason !== '' ? $reason : null,
                'changed_by' => $request->user()->id,
                'actor_role' => $request->user()->role,
            ]);

            AuditHelper::log(
                'Guest Inquiry Status Updated',
                'Contact Inquiries',
                'ContactInquiry',
                $inquiry->id,
                $inquiry->reference_number,
                ['status' => $oldStatus],
                [
                    'status' => $newStatus,
                    'reason' => $reason !== '' ? $reason : null,
                    'actor_role' => $request->user()->role,
                    'customer_response_included' => $newStatus === 'resolved' && $customerResponse !== '',
                    'resolution_email_status' => $newStatus === 'resolved' ? $updates['resolution_email_status'] : null,
                ],
                'updated',
                $request->user()
            );

            return $inquiry->load([
                'assignee:id,name',
                'statusHistory.actor:id,name',
            ]);
        });

        if ($updated->status === 'resolved' && $updated->resolution_email_status === 'pending') {
            try {
                SendContactInquiryResolution::dispatch(
                    $updated->id,
                    (int) $updated->resolution_email_version
                )->afterCommit();
            } catch (\Throwable $exception) {
                ContactInquiry::query()
                    ->whereKey($updated->id)
                    ->where('resolution_email_version', $updated->resolution_email_version)
                    ->where('resolution_email_status', 'pending')
                    ->update([
                        'resolution_email_status' => 'failed',
                        'resolution_email_error' => Str::limit($exception->getMessage(), 2000, ''),
                    ]);

                Log::error('Contact inquiry resolution dispatch failed', [
                    'inquiry_id' => $updated->id,
                    'resolution_version' => $updated->resolution_email_version,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $updated = $updated->fresh()->load([
            'assignee:id,name',
            'statusHistory.actor:id,name',
        ]);

        $message = match (true) {
            $updated->status !== 'resolved' => 'Inquiry status updated.',
            $updated->resolution_email_status === 'sent' => 'Inquiry resolved and the customer email was sent.',
            $updated->resolution_email_status === 'pending' || $updated->resolution_email_status === 'sending' => 'Inquiry resolved and the customer email was queued.',
            $updated->resolution_email_status === 'not_applicable' => 'Inquiry resolved. No customer email address is available.',
            default => 'Inquiry resolved, but the customer email could not be queued.',
        };

        return response()->json(['success' => true, 'message' => $message, 'data' => $updated]);
    }
}
