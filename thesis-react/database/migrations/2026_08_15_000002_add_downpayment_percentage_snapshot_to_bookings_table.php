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
            $table->decimal('downpayment_percentage', 5, 2)->nullable()->after('tax_rate');
        });

        $rawPercentage = DB::table('system_settings')
            ->where('key', 'downpayment_percentage')
            ->value('value');
        $decodedPercentage = json_decode((string) $rawPercentage, true);
        $fallbackPercentage = is_numeric($decodedPercentage)
            ? (float) $decodedPercentage
            : 50.0;

        DB::table('bookings')
            ->select(['id', 'total_amount'])
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use ($fallbackPercentage) {
                foreach ($bookings as $booking) {
                    $paymentAmount = DB::table('payments')
                        ->where('booking_id', $booking->id)
                        ->where('payment_type', 'downpayment')
                        ->orderByDesc('id')
                        ->value('amount');
                    $total = (float) $booking->total_amount;
                    $percentage = $total > 0.009 && $paymentAmount !== null
                        ? ((float) $paymentAmount / $total) * 100
                        : $fallbackPercentage;

                    DB::table('bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'downpayment_percentage' => round(min(100, max(0, $percentage)), 2),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('downpayment_percentage');
        });
    }
};
