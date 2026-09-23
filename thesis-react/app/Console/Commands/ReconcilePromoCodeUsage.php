<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Services\PromoCodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcilePromoCodeUsage extends Command
{
    protected $signature = 'promos:reconcile-usage {--dry-run : Report inconsistencies without changing data}';

    protected $description = 'Reconcile promo reservation lifecycle and consumed usage counters';

    public function __construct(private PromoCodeService $promoCodeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $lifecycleChanges = 0;
        $counterChanges = 0;

        PromoCodeUsage::query()->with('booking')->orderBy('id')->chunkById(200, function ($usages) use ($dryRun, &$lifecycleChanges) {
            foreach ($usages as $usage) {
                $booking = $usage->booking;
                if (!$booking) {
                    continue;
                }

                $expected = $this->expectedStatus($usage, $booking);
                if ($expected === $usage->status) {
                    continue;
                }

                $lifecycleChanges++;
                if (!$dryRun) {
                    DB::transaction(function () use ($booking) {
                        $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->first();
                        if ($lockedBooking) {
                            $this->promoCodeService->synchronizeForBooking($lockedBooking);
                        }
                    });
                }
            }
        });

        PromoCode::query()->orderBy('id')->chunkById(200, function ($promoCodes) use ($dryRun, &$counterChanges) {
            foreach ($promoCodes as $promoCode) {
                $expected = $promoCode->usages()->where('status', PromoCodeUsage::STATUS_CONSUMED)->count();
                if ((int) $promoCode->total_used === $expected) {
                    continue;
                }

                $counterChanges++;
                if (!$dryRun) {
                    $promoCode->update(['total_used' => $expected]);
                }
            }
        });

        $prefix = $dryRun ? 'Dry run' : 'Reconciled';
        $this->info("{$prefix}: lifecycle_changes={$lifecycleChanges}, counter_changes={$counterChanges}.");

        return self::SUCCESS;
    }

    private function expectedStatus(PromoCodeUsage $usage, Booking $booking): string
    {
        if (in_array($booking->booking_status, ['confirmed', 'checked_in', 'checked_out'], true)) {
            return PromoCodeUsage::STATUS_CONSUMED;
        }

        if (
            $usage->status === PromoCodeUsage::STATUS_RESERVED
            && in_array($booking->booking_status, ['cancelled', 'rejected', 'no_show'], true)
        ) {
            return PromoCodeUsage::STATUS_RELEASED;
        }

        return $usage->status;
    }
}
