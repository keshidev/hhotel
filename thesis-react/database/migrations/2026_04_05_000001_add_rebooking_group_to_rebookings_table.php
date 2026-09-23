<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rebookings', function (Blueprint $table) {
            if (!Schema::hasColumn('rebookings', 'rebooking_group_id')) {
                $table->unsignedBigInteger('rebooking_group_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('rebookings', 'is_group_leader')) {
                $table->boolean('is_group_leader')->default(true)->after('rebooking_group_id');
            }
        });

        DB::table('rebookings')
            ->whereNull('rebooking_group_id')
            ->update(['rebooking_group_id' => DB::raw('id')]);

        DB::table('rebookings')
            ->whereNull('is_group_leader')
            ->update(['is_group_leader' => 1]);

        $this->rebuildOpenRequestUniqueConstraint(true);

        Schema::table('rebookings', function (Blueprint $table) {
            $table->index(['rebooking_group_id', 'created_at'], 'rebookings_group_created_idx');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('rebookings', function (Blueprint $table) {
                $table->dropIndex('rebookings_group_created_idx');
            });
        } catch (\Throwable $e) {
            // Index may not exist in partially applied environments.
        }

        $this->rebuildOpenRequestUniqueConstraint(false);

        Schema::table('rebookings', function (Blueprint $table) {
            if (Schema::hasColumn('rebookings', 'is_group_leader')) {
                $table->dropColumn('is_group_leader');
            }

            if (Schema::hasColumn('rebookings', 'rebooking_group_id')) {
                $table->dropColumn('rebooking_group_id');
            }
        });
    }

    private function rebuildOpenRequestUniqueConstraint(bool $withGroupLeaderGate): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            try {
                DB::statement('DROP INDEX rbk_booking_open_unique ON rebookings');
            } catch (\Throwable $e) {
                // Ignore when index is absent.
            }

            if (Schema::hasColumn('rebookings', 'open_request_slot')) {
                Schema::table('rebookings', function (Blueprint $table) {
                    $table->dropColumn('open_request_slot');
                });
            }

            $gateExpression = $withGroupLeaderGate
                ? ' AND is_group_leader = 1'
                : '';

            DB::statement(
                "ALTER TABLE rebookings
                 ADD COLUMN open_request_slot TINYINT
                 GENERATED ALWAYS AS (
                    CASE
                        WHEN finalized_at IS NULL
                         AND status IN ('pending', 'under_review', 'approved', 'awaiting_payment'){$gateExpression}
                        THEN 1
                        ELSE NULL
                    END
                 ) STORED"
            );

            DB::statement(
                'CREATE UNIQUE INDEX rbk_booking_open_unique
                 ON rebookings (original_booking_id, open_request_slot)'
            );

            return;
        }

        try {
            DB::statement('DROP INDEX IF EXISTS rebookings_open_request_unique');
        } catch (\Throwable $e) {
            // Ignore when index is absent.
        }

        $whereClause = $withGroupLeaderGate
            ? "WHERE finalized_at IS NULL
               AND status IN ('pending', 'under_review', 'approved', 'awaiting_payment')
               AND is_group_leader = 1"
            : "WHERE finalized_at IS NULL
               AND status IN ('pending', 'under_review', 'approved', 'awaiting_payment')";

        DB::statement(
            "CREATE UNIQUE INDEX rebookings_open_request_unique
             ON rebookings (original_booking_id)
             {$whereClause}"
        );
    }
};
