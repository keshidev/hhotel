<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'checked_in_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('checked_in_at')->nullable()->after('room_assigned_at');
            });
        }

        if (! Schema::hasColumn('bookings', 'checked_in_by')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->foreignId('checked_in_by')
                    ->nullable()
                    ->after('checked_in_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('bookings', 'early_check_in_reason')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('early_check_in_reason', 500)->nullable()->after('checked_in_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'early_check_in_reason')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('early_check_in_reason'));
        }

        if (Schema::hasColumn('bookings', 'checked_in_by')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('checked_in_by'));
        }

        if (Schema::hasColumn('bookings', 'checked_in_at')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('checked_in_at'));
        }
    }
};
