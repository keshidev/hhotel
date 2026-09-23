<?php

namespace App\Console\Commands;

use App\Helpers\AuditHelper;
use App\Mail\ManualGcashStatusMail;
use App\Models\Booking;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Services\CancellationApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ExpireAbandonedBookings
 *
 * FIX #3 — Pending bookings were locking inventory indefinitely.
 *
 * A guest who creates a booking but never opens the QR / pays leaves the
 * booking in 'pending' status, which blocks those rooms from being booked
 * by anyone else for the same dates.
 *
 * This command cancels bookings that have been pending for longer than the
 * configured threshold (default: 30 minutes) with no completed payment,
 * and releases the rooms back to available.
 *
 * Schedule: every 15 minutes (see routes/console.php).
 */
class ExpireAbandonedBookings extends Command
{
    public function __construct(private CancellationApprovalService $cancellationApprovalService)
    {
        parent::__construct();
    }

    protected $signature = 'bookings:expire-abandoned {--dry-run : Log what would change without writing to the database}';

    protected $description = 'Cancel pending bookings that have not been paid within the expiry window';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $thresholdMins = (int) config('bookings.pending_expiry_minutes', 30);
        $cutoff = now()->subMinutes($thresholdMins);

        // Find bookings that are:
        //   - still pending
        //   - older than the threshold
        //   - have no completed payment
        $abandonedQuery = Booking::where('booking_status', 'pending')
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($hasExpiryQ) {
                    $hasExpiryQ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now());
                })->orWhere(function ($legacyQ) use ($cutoff) {
                    $legacyQ->whereNull('expires_at')
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->whereDoesntHave('payments', fn ($q) => $q->where('payment_status', 'completed'))
            ->whereDoesntHave('payments', function ($query) {
                $query->where('provider', 'manual_gcash')
                    ->whereIn('lifecycle_status', [
                        Payment::LIFECYCLE_PROOF_SUBMITTED,
                        Payment::LIFECYCLE_PENDING_VERIFICATION,
                        Payment::LIFECYCLE_PAID_UNDER_REVIEW,
                    ]);
            })
            ->with(['bookingRooms.room', 'primaryGuest', 'payments']);

        $candidateCount = (clone $abandonedQuery)->count();
        if ($candidateCount === 0) {
            $this->info('No abandoned bookings found.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidateCount} abandoned booking candidate(s) (pending > {$thresholdMins} min, no completed payment).");
        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be written.');
        }

        $cancelled = 0;
        $abandoned = $abandonedQuery->lazyById(100);

        foreach ($abandoned as $booking) {
            $guestName = $booking->primaryGuest?->name ?? 'Guest';
            $this->line("  Booking #{$booking->id} ({$booking->reference_number}) — {$guestName}");

            if ($dryRun) {
                $this->warn('    → Would cancel booking and release rooms.');
                $cancelled++;

                continue;
            }

            try {
                $wasCancelled = DB::transaction(function () use ($booking, $guestName, $cutoff) {
                    $submissions = ManualGcashSubmission::query()
                        ->where('booking_id', $booking->id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $payments = Payment::query()
                        ->where('booking_id', $booking->id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

                    if (! $locked || ! $this->isStillEligibleForExpiry($locked, $payments, $submissions, $cutoff)) {
                        return false;
                    }

                        $locked->payments()
                            ->where('provider', 'manual_gcash')
                            ->where('payment_status', 'pending')
                            ->update([
                                'lifecycle_status' => Payment::LIFECYCLE_EXPIRED,
                                'lifecycle_message' => 'Payment window expired before an approved proof was received.',
                            ]);

                        $this->cancellationApprovalService->cancelLockedImmediately(
                            booking: $locked,
                            payload: [
                                'reason' => 'Booking expired: guest did not complete payment within the allowed time.',
                                'cancelled_reason_code' => 'expired_unpaid',
                                'refund_status' => 'none',
                                'refund_amount' => 0,
                                'request_note' => 'Automatic expiry cancellation job.',
                                'require_unpaid' => true,
                            ],
                            actorUser: null,
                            actorLabel: 'System (Expiry)'
                        );
                    AuditHelper::log(
                        actionActivity: 'Booking Expired (Abandoned)',
                        modulePage: 'Booking Module',
                        modelType: 'Booking',
                        modelId: $booking->id,
                        recordAffected: 'Booking '.$booking->reference_number.' ('.$guestName.')',
                        oldValues: ['booking_status' => 'pending'],
                        newValues: ['booking_status' => 'cancelled', 'reason' => 'No payment received within expiry window'],
                        action: 'updated',
                        actorLabel: 'System (Expiry)'
                    );

                    Log::info('Booking expired and cancelled', [
                        'booking_id' => $booking->id,
                        'reference_number' => $booking->reference_number,
                    ]);

                    return true;
                });

                if (! $wasCancelled) {
                    $this->warn('    → Skipped because payment or proof state changed before cancellation.');
                    continue;
                }

                $expiredBooking = $booking->fresh(['primaryGuest', 'payments.manualGcashSubmissions']);
                $manualPayment = $expiredBooking?->payments->firstWhere('provider', 'manual_gcash');
                $latestSubmission = $manualPayment?->manualGcashSubmissions?->sortByDesc('id')->first();
                if ($expiredBooking?->primaryGuest?->email && $manualPayment) {
                    try {
                        Mail::to($expiredBooking->primaryGuest->email)->queue(
                            new ManualGcashStatusMail(
                                $expiredBooking,
                                $latestSubmission,
                                'expired',
                                'No approved payment proof was received within the payment window.'
                            )
                        );
                    } catch (\Throwable $mailException) {
                        Log::error('Manual GCash expiry email could not be queued', [
                            'booking_id' => $booking->id,
                            'error' => $mailException->getMessage(),
                        ]);
                    }
                }

                $this->info('    → Cancelled. Rooms released.');
                $cancelled++;

            } catch (\Throwable $e) {
                Log::error('ExpireAbandonedBookings: failed to cancel booking', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("    → Failed: {$e->getMessage()}");
            }
        }

        $this->info("Expiry complete. Cancelled: {$cancelled}");

        return self::SUCCESS;
    }

    private function isStillEligibleForExpiry(
        Booking $booking,
        $payments,
        $submissions,
        $legacyCutoff
    ): bool {
        if ($booking->booking_status !== 'pending') {
            return false;
        }

        $expired = $booking->expires_at
            ? $booking->expires_at->lte(now())
            : $booking->created_at->lt($legacyCutoff);

        if (! $expired || $payments->contains('payment_status', 'completed')) {
            return false;
        }

        $protectedLifecycles = [
            Payment::LIFECYCLE_PROOF_SUBMITTED,
            Payment::LIFECYCLE_PENDING_VERIFICATION,
            Payment::LIFECYCLE_PAID_UNDER_REVIEW,
            Payment::LIFECYCLE_PAID,
        ];

        if ($payments->contains(fn (Payment $payment) => in_array($payment->lifecycle_status, $protectedLifecycles, true))) {
            return false;
        }

        return ! $submissions->contains(fn (ManualGcashSubmission $submission) => in_array(
            $submission->status,
            [
                ManualGcashSubmission::STATUS_PENDING,
                ManualGcashSubmission::STATUS_ESCALATED,
                ManualGcashSubmission::STATUS_APPROVED,
            ],
            true
        ));
    }
}
