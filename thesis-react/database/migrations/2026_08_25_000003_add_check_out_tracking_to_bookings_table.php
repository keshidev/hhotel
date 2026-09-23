<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'checked_out_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('checked_out_at')->nullable()->after('early_check_in_reason');
            });
        }

        if (! Schema::hasColumn('bookings', 'checked_out_by')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->foreignId('checked_out_by')
                    ->nullable()
                    ->after('checked_out_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'checked_out_by')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('checked_out_by'));
        }

        if (Schema::hasColumn('bookings', 'checked_out_at')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('checked_out_at'));
        }
    }
};
