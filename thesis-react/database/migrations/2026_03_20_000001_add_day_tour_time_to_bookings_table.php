<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add day-tour time tracking columns to the bookings table.
     *
     * day_tour_start_time — the time the receptionist created the day tour
     *                       (recorded at booking creation in WalkInController)
     * day_tour_end_time   — start_time + 12 hours (computed on creation)
     *
     * Both are nullable — only set when is_day_tour = true.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->time('day_tour_start_time')->nullable()->after('is_day_tour');
            $table->time('day_tour_end_time')->nullable()->after('day_tour_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['day_tour_start_time', 'day_tour_end_time']);
        });
    }
};