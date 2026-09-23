<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('promo_code_id')
                  ->nullable()
                  ->after('is_day_tour')
                  ->constrained('promo_codes')
                  ->onDelete('set null');

            // Snapshot of the discount applied at booking time.
            // Stored here so reports are accurate even if the promo is later edited.
            $table->decimal('discount_amount', 10, 2)
                  ->default(0)
                  ->after('promo_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['promo_code_id']);
            $table->dropColumn(['promo_code_id', 'discount_amount']);
        });
    }
};