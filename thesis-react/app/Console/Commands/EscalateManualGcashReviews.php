<?php

namespace App\Console\Commands;

use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EscalateManualGcashReviews extends Command
{
    protected $signature = 'payments:manual-gcash-escalate {--dry-run}';

    protected $description = 'Escalate overdue manual GCash proof reviews to administrators without cancelling bookings';

    public function handle(): int
    {
        $ids = ManualGcashSubmission::query()
            ->where('status', ManualGcashSubmission::STATUS_PENDING)
            ->where('escalation_due_at', '<=', now())
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No overdue manual GCash reviews found.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            if ($this->option('dry-run')) {
                $this->line("Would escalate submission {$id}.");

                continue;
            }

            DB::transaction(function () use ($id) {
                $submission = ManualGcashSubmission::whereKey($id)->lockForUpdate()->first();
                if (! $submission || $submission->status !== ManualGcashSubmission::STATUS_PENDING) {
                    return;
                }

                $payment = Payment::whereKey($submission->payment_id)->lockForUpdate()->first();
                if (! $payment || $payment->payment_status !== 'pending') {
                    return;
                }

                $submission->update(['status' => ManualGcashSubmission::STATUS_ESCALATED]);
                $payment->update([
                    'lifecycle_status' => Payment::LIFECYCLE_PAID_UNDER_REVIEW,
                    'lifecycle_message' => 'Manual GCash proof exceeded the review hold and requires administrator resolution.',
                ]);

                $booking = $payment->booking()->lockForUpdate()->first();
                if ($booking) {
                    $holdUntil = now()->addDay();
                    if ($booking->check_in && $booking->check_in->greaterThan($holdUntil)) {
                        $holdUntil = $booking->check_in->copy();
                    }
                    $booking->update(['expires_at' => $holdUntil]);
                }

                Log::critical('Manual GCash review escalated to administrator', [
                    'submission_id' => $submission->id,
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id,
                ]);
            }, 3);
        }

        $this->info('Escalated '.$ids->count().' manual GCash review(s).');

        return self::SUCCESS;
    }
}
