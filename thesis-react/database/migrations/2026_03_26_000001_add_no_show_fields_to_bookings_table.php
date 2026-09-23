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
            if (!Schema::hasColumn('bookings', 'no_show_marked_at')) {
                $table->timestamp('no_show_marked_at')->nullable()->after('booking_status');
            }
            if (!Schema::hasColumn('bookings', 'no_show_contacted')) {
                $table->boolean('no_show_contacted')->nullable()->after('no_show_marked_at');
            }
        });

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("
                ALTER TABLE bookings
                MODIFY booking_status ENUM('pending','confirmed','checked_in','checked_out','cancelled','no_show')
                NOT NULL DEFAULT 'pending'
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE bookings SET booking_status = 'cancelled' WHERE booking_status = 'no_show'");
            DB::statement("
                ALTER TABLE bookings
                MODIFY booking_status ENUM('pending','confirmed','checked_in','checked_out','cancelled')
                NOT NULL DEFAULT 'pending'
            ");
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'no_show_contacted')) {
                $table->dropColumn('no_show_contacted');
            }
            if (Schema::hasColumn('bookings', 'no_show_marked_at')) {
                $table->dropColumn('no_show_marked_at');
            }
        });
    }
};

