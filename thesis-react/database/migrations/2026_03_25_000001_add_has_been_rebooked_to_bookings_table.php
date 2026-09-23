<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'has_been_rebooked')) {
                $column = $table->boolean('has_been_rebooked')->default(false);

                if (Schema::hasColumn('bookings', 'addons_breakdown')) {
                    $column->after('addons_breakdown');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'has_been_rebooked')) {
                $table->dropColumn('has_been_rebooked');
            }
        });
    }
};
