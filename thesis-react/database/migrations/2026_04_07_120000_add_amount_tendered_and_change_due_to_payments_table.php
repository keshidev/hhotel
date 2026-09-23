<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasAmountTendered = Schema::hasColumn('payments', 'amount_tendered');
        $hasChangeDue = Schema::hasColumn('payments', 'change_due');

        if (!$hasAmountTendered || !$hasChangeDue) {
            Schema::table('payments', function (Blueprint $table) use ($hasAmountTendered, $hasChangeDue) {
                if (!$hasAmountTendered) {
                    $table->decimal('amount_tendered', 10, 2)->nullable()->after('amount');
                }

                if (!$hasChangeDue) {
                    $table->decimal('change_due', 10, 2)->default(0)->after('amount_tendered');
                }
            });
        }

        if (Schema::hasColumn('payments', 'amount_tendered')) {
            DB::table('payments')
                ->whereNull('amount_tendered')
                ->update(['amount_tendered' => DB::raw('amount')]);
        }

        if (Schema::hasColumn('payments', 'change_due')) {
            DB::table('payments')
                ->whereNull('change_due')
                ->update(['change_due' => 0]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'change_due')) {
                $table->dropColumn('change_due');
            }
            if (Schema::hasColumn('payments', 'amount_tendered')) {
                $table->dropColumn('amount_tendered');
            }
        });
    }
};
