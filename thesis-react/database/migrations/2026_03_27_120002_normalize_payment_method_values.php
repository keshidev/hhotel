<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $gcashVariants = [
            'gcash',
            'gcash via paymongo',
            'gcash(paymongo)',
            'gcash (paymongo)',
            'gcash_paymongo',
        ];

        $cashVariants = [
            'cash',
            'card',
            'credit card',
            'credit_card',
            'debit card',
            'debit_card',
            'bank transfer',
            'bank_transfer',
            'pay at hotel',
            'manual',
        ];

        if (Schema::hasColumn('payments', 'payment_method')) {
            DB::table('payments')
                ->where('provider', 'paymongo')
                ->update(['payment_method' => 'gcash']);

            DB::table('payments')
                ->whereNotNull('payment_method')
                ->whereRaw("LOWER(TRIM(payment_method)) IN ('" . implode("','", $gcashVariants) . "')")
                ->update(['payment_method' => 'gcash']);

            DB::table('payments')
                ->whereNotNull('payment_method')
                ->whereRaw("LOWER(TRIM(payment_method)) IN ('" . implode("','", $cashVariants) . "')")
                ->update(['payment_method' => 'cash']);
        }

        if (Schema::hasColumn('bookings', 'payment_method')) {
            DB::table('bookings')
                ->whereNotNull('payment_method')
                ->whereRaw("LOWER(TRIM(payment_method)) IN ('" . implode("','", $gcashVariants) . "')")
                ->update(['payment_method' => 'gcash']);

            DB::table('bookings')
                ->whereNotNull('payment_method')
                ->whereRaw("LOWER(TRIM(payment_method)) IN ('" . implode("','", $cashVariants) . "')")
                ->update(['payment_method' => 'cash']);
        }
    }

    public function down(): void
    {
        // One-way normalization; original variant values are intentionally not restored.
    }
};

