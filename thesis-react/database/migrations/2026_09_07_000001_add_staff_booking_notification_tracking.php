<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('staff_booking_notified_at')
                ->nullable()
                ->after('confirmation_email_last_error');
            $table->index('staff_booking_notified_at', 'bookings_staff_booking_notified_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_staff_booking_notified_at_index');
            $table->dropColumn('staff_booking_notified_at');
        });
    }
};
