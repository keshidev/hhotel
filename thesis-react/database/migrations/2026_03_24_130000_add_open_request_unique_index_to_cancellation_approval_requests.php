<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS car_open_request_unique
                ON cancellation_approval_requests (booking_id)
                WHERE finalized_at IS NULL
                  AND status IN ('pending_approval', 'approved', 'refund_pending', 'refunded')"
            );
            return;
        }

        if ($driver === 'mysql') {
            if (!Schema::hasColumn('cancellation_approval_requests', 'open_request_slot')) {
                DB::statement(
                    "ALTER TABLE cancellation_approval_requests
                     ADD COLUMN open_request_slot TINYINT
                     GENERATED ALWAYS AS (
                        CASE
                            WHEN finalized_at IS NULL
                             AND status IN ('pending_approval', 'approved', 'refund_pending', 'refunded')
                            THEN 1
                            ELSE NULL
                        END
                     ) STORED"
                );
            }

            DB::statement(
                "CREATE UNIQUE INDEX car_booking_open_unique
                 ON cancellation_approval_requests (booking_id, open_request_slot)"
            );
            return;
        }

        // Fallback for other SQL engines that support partial indexes.
        DB::statement(
            "CREATE UNIQUE INDEX car_open_request_unique
            ON cancellation_approval_requests (booking_id)
            WHERE finalized_at IS NULL
              AND status IN ('pending_approval', 'approved', 'refund_pending', 'refunded')"
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS car_open_request_unique');
            return;
        }

        if ($driver === 'mysql') {
            // MySQL 8.0+ supports IF EXISTS for indexes in drop statements.
            DB::statement('DROP INDEX car_booking_open_unique ON cancellation_approval_requests');

            if (Schema::hasColumn('cancellation_approval_requests', 'open_request_slot')) {
                Schema::table('cancellation_approval_requests', function (Blueprint $table) {
                    $table->dropColumn('open_request_slot');
                });
            }
            return;
        }

        DB::statement('DROP INDEX IF EXISTS car_open_request_unique');
    }
};

