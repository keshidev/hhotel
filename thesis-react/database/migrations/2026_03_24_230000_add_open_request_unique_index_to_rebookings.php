<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rebookings', 'finalized_at')) {
            Schema::table('rebookings', function (Blueprint $table) {
                $table->timestamp('finalized_at')->nullable()->after('approved_at');
            });
        }

        // Backfill historical terminal rows so only unresolved requests remain "open".
        DB::table('rebookings')
            ->whereIn('status', ['approved', 'rejected', 'cancelled', 'completed', 'closed'])
            ->whereNull('finalized_at')
            ->update([
                'finalized_at' => DB::raw('COALESCE(approved_at, updated_at, created_at)'),
            ]);

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS rebookings_open_request_unique
                ON rebookings (original_booking_id)
                WHERE finalized_at IS NULL
                  AND status IN ('pending', 'under_review', 'approved', 'awaiting_payment')"
            );
            return;
        }

        if ($driver === 'mysql') {
            if (!Schema::hasColumn('rebookings', 'open_request_slot')) {
                DB::statement(
                    "ALTER TABLE rebookings
                     ADD COLUMN open_request_slot TINYINT
                     GENERATED ALWAYS AS (
                        CASE
                            WHEN finalized_at IS NULL
                             AND status IN ('pending', 'under_review', 'approved', 'awaiting_payment')
                            THEN 1
                            ELSE NULL
                        END
                     ) STORED"
                );
            }

            DB::statement(
                "CREATE UNIQUE INDEX rbk_booking_open_unique
                 ON rebookings (original_booking_id, open_request_slot)"
            );
            return;
        }

        DB::statement(
            "CREATE UNIQUE INDEX rebookings_open_request_unique
            ON rebookings (original_booking_id)
            WHERE finalized_at IS NULL
              AND status IN ('pending', 'under_review', 'approved', 'awaiting_payment')"
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS rebookings_open_request_unique');
        } elseif ($driver === 'mysql') {
            DB::statement('DROP INDEX rbk_booking_open_unique ON rebookings');

            if (Schema::hasColumn('rebookings', 'open_request_slot')) {
                Schema::table('rebookings', function (Blueprint $table) {
                    $table->dropColumn('open_request_slot');
                });
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS rebookings_open_request_unique');
        }

        if (Schema::hasColumn('rebookings', 'finalized_at')) {
            Schema::table('rebookings', function (Blueprint $table) {
                $table->dropColumn('finalized_at');
            });
        }
    }
};

