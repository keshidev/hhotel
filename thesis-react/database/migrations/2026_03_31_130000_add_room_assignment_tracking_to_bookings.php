<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('bookings', 'room_assignment_status')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('room_assignment_status', 32)
                    ->default('pending_assignment')
                    ->after('reservation_status');
                $table->index('room_assignment_status', 'bookings_room_assignment_status_idx');
            });
        }

        if (!Schema::hasColumn('bookings', 'room_assigned_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('room_assigned_at')->nullable()->after('room_assignment_status');
            });
        }

        if (!Schema::hasColumn('booking_rooms', 'requested_room_type')) {
            Schema::table('booking_rooms', function (Blueprint $table) {
                $table->string('requested_room_type', 50)->nullable()->after('room_id');
                $table->index('requested_room_type', 'booking_rooms_requested_room_type_idx');
            });
        }

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'room_assigned_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('room_assigned_at');
            });
        }

        if (Schema::hasColumn('bookings', 'room_assignment_status')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropIndex('bookings_room_assignment_status_idx');
                $table->dropColumn('room_assignment_status');
            });
        }

        if (Schema::hasColumn('booking_rooms', 'requested_room_type')) {
            Schema::table('booking_rooms', function (Blueprint $table) {
                $table->dropIndex('booking_rooms_requested_room_type_idx');
                $table->dropColumn('requested_room_type');
            });
        }

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable(false)->change();
        });
    }
};
