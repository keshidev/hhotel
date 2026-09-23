<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('booking_status', 'bookings_booking_status_index');
            $table->index('created_at', 'bookings_created_at_index');
            $table->index(['check_in', 'check_out'], 'bookings_check_in_out_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('paid_at', 'payments_paid_at_index');
            $table->index('payment_status', 'payments_payment_status_index');
            $table->index('payment_type', 'payments_payment_type_index');
            $table->index(['payment_status', 'paid_at'], 'payments_status_paid_at_index');
        });

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->index('room_id', 'booking_rooms_room_id_index');
            $table->index('booking_id', 'booking_rooms_booking_id_index');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->index('status', 'rooms_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_booking_status_index');
            $table->dropIndex('bookings_created_at_index');
            $table->dropIndex('bookings_check_in_out_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_paid_at_index');
            $table->dropIndex('payments_payment_status_index');
            $table->dropIndex('payments_payment_type_index');
            $table->dropIndex('payments_status_paid_at_index');
        });

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropIndex('booking_rooms_room_id_index');
            $table->dropIndex('booking_rooms_booking_id_index');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex('rooms_status_index');
        });
    }
};
