<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 5)->nullable()->after('tax_amount');
        });

        $fallbackRate = (float) config('payments.tax_rate', 0.12);
        $rawPercentage = DB::table('system_settings')
            ->where('key', 'tax_percentage')
            ->value('value');
        $decodedPercentage = json_decode((string) $rawPercentage, true);
        $percentage = is_numeric($decodedPercentage)
            ? (float) $decodedPercentage
            : $fallbackRate * 100;
        $rate = round(min(1, max(0, $percentage / 100)), 5);

        DB::table('bookings')
            ->whereNull('tax_rate')
            ->update(['tax_rate' => $rate]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
