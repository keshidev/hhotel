<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $dupRefs = DB::table('payments')
            ->select('provider_reference')
            ->whereNotNull('provider_reference')
            ->groupBy('provider_reference')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('provider_reference');

        foreach ($dupRefs as $providerReference) {
            $ids = DB::table('payments')
                ->where('provider_reference', $providerReference)
                ->orderBy('id')
                ->pluck('id');

            if ($ids->count() <= 1) {
                continue;
            }

            DB::table('payments')
                ->whereIn('id', $ids->slice(1)->all())
                ->update(['provider_reference' => null]);
        }

        $dupSources = DB::table('payments')
            ->select('provider', 'provider_payment_id')
            ->whereNotNull('provider')
            ->whereNotNull('provider_payment_id')
            ->groupBy('provider', 'provider_payment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupSources as $dup) {
            $ids = DB::table('payments')
                ->where('provider', $dup->provider)
                ->where('provider_payment_id', $dup->provider_payment_id)
                ->orderBy('id')
                ->pluck('id');

            if ($ids->count() <= 1) {
                continue;
            }

            DB::table('payments')
                ->whereIn('id', $ids->slice(1)->all())
                ->update([
                    'provider_payment_id' => null,
                    'qr_url' => null,
                    'checkout_url' => null,
                ]);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['provider', 'provider_payment_id'], 'payments_provider_source_unique');
            $table->unique('provider_reference', 'payments_provider_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_provider_source_unique');
            $table->dropUnique('payments_provider_reference_unique');
        });
    }
};
