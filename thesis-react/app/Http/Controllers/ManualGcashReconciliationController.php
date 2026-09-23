<?php

namespace App\Http\Controllers;

use App\Helpers\AuditHelper;
use App\Models\Booking;
use App\Models\ManualGcashReconciliation;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Services\CancellationApprovalService;
use App\Services\ManualGcashReconciliationAgingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManualGcashReconciliationController extends Controller
{
    public function __construct(
        private CancellationApprovalService $cancellationApprovalService,
        private ManualGcashReconciliationAgingService $reconciliationAging
    ) {}

    public function summary()
    {
        $approved = ManualGcashSubmission::query()
            ->where('status', ManualGcashSubmission::STATUS_APPROVED);

        return response()->json([
            'pending_verification' => ManualGcashSubmission::query()
                ->where('status', ManualGcashSubmission::STATUS_PENDING)
                ->count(),
            'escalated_review' => ManualGcashSubmission::query()
                ->where('status', ManualGcashSubmission::STATUS_ESCALATED)
                ->count(),
            'pending_review' => ManualGcashSubmission::query()
                ->whereIn('status', [ManualGcashSubmission::STATUS_PENDING, ManualGcashSubmission::STATUS_ESCALATED])
                ->count(),
            'overdue_review' => ManualGcashSubmission::query()
                ->where(function ($query) {
                    $query->where('status', ManualGcashSubmission::STATUS_ESCALATED)
                        ->orWhere(function ($pending) {
                            $pending->where('status', ManualGcashSubmission::STATUS_PENDING)
                                ->where('review_due_at', '<', now());
                        });
                })
                ->count(),
            'refund_required' => Payment::query()
                ->where('provider', 'manual_gcash')
                ->where('lifecycle_status', Payment::LIFECYCLE_REFUND_REQUIRED)
                ->count(),
            'unreconciled' => (clone $approved)->whereDoesntHave('reconciliation')->count(),
            'overdue_unreconciled' => $this->reconciliationAging->overdueCount(),
            'oldest_unreconciled_age_minutes' => $this->reconciliationAging->oldestAgeMinutes(),
            'reconciliation_max_age_minutes' => $this->reconciliationAging->maxAgeMinutes(),
            'open_exceptions' => ManualGcashReconciliation::query()
                ->where('status', ManualGcashReconciliation::STATUS_EXCEPTION_OPEN)
                ->count(),
        ]);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:unreconciled,matched,exception_open,exception_resolved'],
            'queue' => ['nullable', 'in:needs_reconciliation,exceptions,history'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $queue = $validated['queue'] ?? null;
        $status = $validated['status'] ?? ($queue ? null : 'unreconciled');
        $query = ManualGcashSubmission::query()
            ->where('status', ManualGcashSubmission::STATUS_APPROVED)
            ->with([
                'booking.primaryGuest', 'payment', 'reviewer:id,name',
                'reconciliation.reconciler:id,name', 'reconciliation.resolver:id,name',
            ])
            ->when($queue === 'needs_reconciliation' || $status === 'unreconciled', fn ($builder) => $builder->whereDoesntHave('reconciliation'))
            ->when($queue === 'exceptions', fn ($builder) => $builder->whereHas(
                'reconciliation',
                fn ($reconciliation) => $reconciliation->where('status', ManualGcashReconciliation::STATUS_EXCEPTION_OPEN)
            ))
            ->when($queue === 'history', function ($builder) use ($status) {
                $historyStatuses = [
                    ManualGcashReconciliation::STATUS_MATCHED,
                    ManualGcashReconciliation::STATUS_EXCEPTION_RESOLVED,
                ];

                return $builder->whereHas('reconciliation', function ($reconciliation) use ($status, $historyStatuses) {
                    if ($status && in_array($status, $historyStatuses, true)) {
                        $reconciliation->where('status', $status);
                    } else {
                        $reconciliation->whereIn('status', $historyStatuses);
                    }
                });
            })
            ->when(! $queue && $status !== 'unreconciled', fn ($builder) => $builder->whereHas(
                'reconciliation',
                fn ($reconciliation) => $reconciliation->where('status', $status)
            ))
            ->when($validated['search'] ?? null, function ($builder, $search) {
                $term = '%'.trim($search).'%';
                $builder->where(function ($nested) use ($term) {
                    $nested->where('active_reference_claim', 'like', $term)
                        ->orWhere('transaction_reference', 'like', $term)
                        ->orWhereHas('booking', fn ($booking) => $booking->where('reference_number', 'like', $term))
                        ->orWhereHas('booking.primaryGuest', fn ($guest) => $guest->where('name', 'like', $term));
                });
            })
            ->when($validated['date_from'] ?? null, fn ($builder, $date) => $builder->whereDate('paid_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($builder, $date) => $builder->whereDate('paid_at', '<=', $date))
            ->orderByDesc('paid_at');

        return response()->json(
            $query->paginate(25)->through(fn (ManualGcashSubmission $submission) => $this->payload($submission))
        );
    }

    public function match(Request $request, ManualGcashSubmission $submission)
    {
        $validated = $this->validateStatement($request);

        try {
            $record = DB::transaction(function () use ($request, $submission, $validated) {
                $locked = ManualGcashSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
                $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
                $existing = ManualGcashReconciliation::where('submission_id', $locked->id)->lockForUpdate()->first();

                $this->assertReconciliationEligible($locked, $payment);
                if ($existing?->status === ManualGcashReconciliation::STATUS_MATCHED) {
                    throw ValidationException::withMessages([
                        'reconciliation' => 'This payment has already been reconciled.',
                    ]);
                }
                if ($existing && $request->user()?->role !== 'admin') {
                    abort(403, 'Only an administrator may close an open reconciliation exception.');
                }

                $normalized = $this->assertExactStatementMatch($locked, $payment, $validated);
                $record = $existing ?? new ManualGcashReconciliation([
                    'submission_id' => $locked->id,
                    'payment_id' => $payment->id,
                    'booking_id' => $locked->booking_id,
                ]);
                $record->fill([
                    'status' => ManualGcashReconciliation::STATUS_MATCHED,
                    'statement_reference' => trim($validated['statement_reference']),
                    'normalized_statement_reference' => $normalized,
                    'matched_reference_claim' => $normalized,
                    'statement_amount' => $validated['statement_amount'],
                    'statement_paid_at' => $validated['statement_paid_at'],
                    'exception_type' => null,
                    'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
                    'reconciled_by' => $request->user()->id,
                    'reconciled_at' => now(),
                    'resolution' => $existing ? ManualGcashReconciliation::RESOLUTION_MATCHED : null,
                    'resolution_notes' => $existing ? trim((string) ($validated['notes'] ?? '')) : null,
                    'resolved_by' => $existing ? $request->user()->id : null,
                    'resolved_at' => $existing ? now() : null,
                ]);
                $record->save();

                $payment->update([
                    'lifecycle_status' => Payment::LIFECYCLE_PAID,
                    'lifecycle_message' => null,
                ]);

                return $record;
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'matched_reference_claim')) {
                throw ValidationException::withMessages([
                    'statement_reference' => 'This merchant statement reference is already reconciled to another booking.',
                ]);
            }

            throw $exception;
        }

        $this->audit($record, 'Manual GCash Payment Reconciled', ['status' => $record->status]);

        return response()->json(['success' => true, 'message' => 'Payment reconciled with the merchant statement.']);
    }

    public function flagException(Request $request, ManualGcashSubmission $submission)
    {
        $validated = $request->validate([
            'exception_type' => ['required', Rule::in(ManualGcashReconciliation::EXCEPTION_TYPES)],
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
            'statement_reference' => ['nullable', 'string', 'max:80'],
            'statement_amount' => ['nullable', 'numeric', 'min:0'],
            'statement_paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $result = DB::transaction(function () use ($request, $submission, $validated) {
            $locked = ManualGcashSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            $this->assertReconciliationEligible($locked, $payment);

            $record = ManualGcashReconciliation::where('submission_id', $locked->id)->lockForUpdate()->first()
                ?? new ManualGcashReconciliation([
                    'submission_id' => $locked->id,
                    'payment_id' => $payment->id,
                    'booking_id' => $locked->booking_id,
                ]);
            if ($record->exists && $record->status === ManualGcashReconciliation::STATUS_EXCEPTION_OPEN) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'This payment already has an open reconciliation exception.',
                ]);
            }
            if ($record->exists
                && $record->status === ManualGcashReconciliation::STATUS_EXCEPTION_RESOLVED
                && $request->user()?->role !== 'admin') {
                abort(403, 'Only an administrator may reopen a resolved reconciliation exception.');
            }
            $oldStatus = $record->status;
            $reference = trim((string) ($validated['statement_reference'] ?? ''));
            $record->fill([
                'status' => ManualGcashReconciliation::STATUS_EXCEPTION_OPEN,
                'statement_reference' => $reference ?: null,
                'normalized_statement_reference' => $reference ? $this->normalizeReference($reference) : null,
                'matched_reference_claim' => null,
                'statement_amount' => $validated['statement_amount'] ?? null,
                'statement_paid_at' => $validated['statement_paid_at'] ?? null,
                'exception_type' => $validated['exception_type'],
                'notes' => trim($validated['notes']),
                'reconciled_by' => $request->user()->id,
                'reconciled_at' => now(),
                'resolution' => null,
                'resolution_notes' => null,
                'resolved_by' => null,
                'resolved_at' => null,
            ]);
            $record->save();
            $payment->update([
                'lifecycle_status' => Payment::LIFECYCLE_PAID_UNDER_REVIEW,
                'lifecycle_message' => 'Manual GCash reconciliation exception requires administrator review.',
            ]);

            return [$record, $oldStatus];
        }, 3);

        $this->audit($result[0], 'Manual GCash Reconciliation Exception Opened', [
            'old_status' => $result[1],
            'status' => $result[0]->status,
            'exception_type' => $result[0]->exception_type,
        ]);

        return response()->json(['success' => true, 'message' => 'Exception sent to the administrator queue.']);
    }

    public function resolve(Request $request, ManualGcashReconciliation $reconciliation)
    {
        $validated = $request->validate([
            'resolution' => ['required', Rule::in([
                ManualGcashReconciliation::RESOLUTION_MATCHED,
                ManualGcashReconciliation::RESOLUTION_PAYMENT_REVIEW,
                ManualGcashReconciliation::RESOLUTION_REFUND_REQUIRED,
            ])],
            'resolution_notes' => ['required', 'string', 'min:10', 'max:2000'],
            'statement_reference' => ['exclude_unless:resolution,matched', 'required', 'string', 'max:80'],
            'statement_amount' => ['exclude_unless:resolution,matched', 'required', 'numeric', 'min:0.01'],
            'statement_paid_at' => ['exclude_unless:resolution,matched', 'required', 'date', 'before_or_equal:now'],
            'merchant_record_confirmed' => ['exclude_unless:resolution,matched', 'required', 'accepted'],
        ]);

        try {
            $record = DB::transaction(function () use ($request, $reconciliation, $validated) {
                $record = ManualGcashReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
                if ($record->status !== ManualGcashReconciliation::STATUS_EXCEPTION_OPEN) {
                    throw ValidationException::withMessages(['reconciliation' => 'This exception is no longer open.']);
                }

                $submission = ManualGcashSubmission::whereKey($record->submission_id)->lockForUpdate()->firstOrFail();
                $payment = Payment::whereKey($record->payment_id)->lockForUpdate()->firstOrFail();
                $resolution = $validated['resolution'];
                $updates = [
                    'status' => ManualGcashReconciliation::STATUS_EXCEPTION_RESOLVED,
                    'resolution' => $resolution,
                    'resolution_notes' => trim($validated['resolution_notes']),
                    'resolved_by' => $request->user()->id,
                    'resolved_at' => now(),
                ];

                if ($resolution === ManualGcashReconciliation::RESOLUTION_MATCHED) {
                    $normalized = $this->assertExactStatementMatch($submission, $payment, $validated);
                    $updates = array_merge($updates, [
                        'status' => ManualGcashReconciliation::STATUS_MATCHED,
                        'statement_reference' => trim($validated['statement_reference']),
                        'normalized_statement_reference' => $normalized,
                        'matched_reference_claim' => $normalized,
                        'statement_amount' => $validated['statement_amount'],
                        'statement_paid_at' => $validated['statement_paid_at'],
                    ]);
                    $payment->update(['lifecycle_status' => Payment::LIFECYCLE_PAID, 'lifecycle_message' => null]);
                } elseif ($resolution === ManualGcashReconciliation::RESOLUTION_REFUND_REQUIRED) {
                    $booking = Booking::whereKey($record->booking_id)->lockForUpdate()->firstOrFail();
                    $this->cancellationApprovalService->ensureManualGcashRefundRequired(
                        booking: $booking,
                        payment: $payment,
                        admin: $request->user(),
                        reason: trim($validated['resolution_notes'])
                    );
                } else {
                    $payment->update([
                        'lifecycle_status' => Payment::LIFECYCLE_PAID_UNDER_REVIEW,
                        'lifecycle_message' => 'Manual GCash payment remains under administrator review after reconciliation.',
                    ]);
                }

                $record->update($updates);

                return $record;
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'matched_reference_claim')) {
                throw ValidationException::withMessages([
                    'statement_reference' => 'This merchant statement reference is already reconciled to another booking.',
                ]);
            }

            throw $exception;
        }

        $this->audit($record, 'Manual GCash Reconciliation Exception Resolved', [
            'status' => $record->status,
            'resolution' => $record->resolution,
        ]);

        return response()->json(['success' => true, 'message' => 'Reconciliation exception resolved.']);
    }

    private function validateStatement(Request $request): array
    {
        return $request->validate([
            'statement_reference' => ['required', 'string', 'min:6', 'max:80'],
            'statement_amount' => ['required', 'numeric', 'min:0.01'],
            'statement_paid_at' => ['required', 'date', 'before_or_equal:now'],
            'merchant_record_confirmed' => ['accepted'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function assertReconciliationEligible(ManualGcashSubmission $submission, Payment $payment): void
    {
        if ($submission->status !== ManualGcashSubmission::STATUS_APPROVED
            || $payment->provider !== 'manual_gcash'
            || $payment->payment_status !== 'completed') {
            throw ValidationException::withMessages([
                'submission' => 'Only approved, completed manual GCash payments can be reconciled.',
            ]);
        }
    }

    private function assertExactStatementMatch(ManualGcashSubmission $submission, Payment $payment, array $data): string
    {
        $normalized = $this->normalizeReference((string) $data['statement_reference']);
        $expectedReference = $submission->active_reference_claim
            ?: $this->normalizeReference($submission->transaction_reference);
        $expectedAmount = (float) ($payment->paid_amount ?? $payment->amount);
        $referenceMatches = hash_equals($expectedReference, $normalized);
        $amountMatches = (int) round((float) $data['statement_amount'] * 100)
            === (int) round($expectedAmount * 100);
        $timeMatches = Carbon::parse($data['statement_paid_at'])->format('Y-m-d H:i')
            === $payment->paid_at?->format('Y-m-d H:i');

        if (! $referenceMatches || ! $amountMatches || ! $timeMatches) {
            throw ValidationException::withMessages([
                'merchant_record' => 'The statement reference, amount, and paid time must exactly match. Flag an exception instead of forcing reconciliation.',
            ]);
        }

        return $normalized;
    }

    private function payload(ManualGcashSubmission $submission): array
    {
        $reconciliation = $submission->reconciliation;

        return [
            'submission_id' => $submission->id,
            'submission_source' => $submission->submission_source,
            'booking_id' => $submission->booking_id,
            'booking_reference' => $submission->booking?->reference_number,
            'guest_name' => $submission->booking?->primaryGuest?->name,
            'transaction_reference' => $submission->active_reference_claim ?: $submission->transaction_reference,
            'amount' => (float) ($submission->payment?->paid_amount ?? $submission->payment?->amount),
            'paid_at' => $submission->payment?->paid_at?->toIso8601String(),
            'paid_at_local' => $submission->payment?->paid_at?->format('Y-m-d\TH:i'),
            'reviewed_by' => $submission->reviewer?->name,
            'reconciliation_timing' => $reconciliation
                ? null
                : $this->reconciliationAging->timingFor($submission),
            'reconciliation' => $reconciliation ? [
                'id' => $reconciliation->id,
                'status' => $reconciliation->status,
                'statement_reference' => $reconciliation->statement_reference,
                'statement_amount' => $reconciliation->statement_amount !== null ? (float) $reconciliation->statement_amount : null,
                'statement_paid_at' => $reconciliation->statement_paid_at?->toIso8601String(),
                'exception_type' => $reconciliation->exception_type,
                'notes' => $reconciliation->notes,
                'reconciled_by' => $reconciliation->reconciler?->name,
                'reconciled_at' => $reconciliation->reconciled_at?->toIso8601String(),
                'resolution' => $reconciliation->resolution,
                'resolution_notes' => $reconciliation->resolution_notes,
                'resolved_by' => $reconciliation->resolver?->name,
                'resolved_at' => $reconciliation->resolved_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function normalizeReference(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($reference)) ?? '');
    }

    private function audit(ManualGcashReconciliation $record, string $activity, array $values): void
    {
        AuditHelper::log(
            actionActivity: $activity,
            modulePage: 'Payment Reconciliation',
            modelType: 'ManualGcashReconciliation',
            modelId: $record->id,
            recordAffected: 'Booking #'.$record->booking_id,
            oldValues: null,
            newValues: $values,
            action: 'updated'
        );
    }
}
