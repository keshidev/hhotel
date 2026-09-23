<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('check_in')->change();
            $table->dateTime('check_out')->change();

            $table->enum('stay_type', ['day_use', 'overnight'])
                  ->nullable()
                  ->after('is_day_tour');

            $table->decimal('duration_hours', 6, 2)
                  ->nullable()
                  ->unsigned()
                  ->after('stay_type')
                  ->comment('Computed: difference between check_out and check_in in hours');
        });

        // Back-fill stay_type from is_day_tour for existing records
        DB::statement("
            UPDATE bookings
            SET stay_type = CASE
                WHEN is_day_tour = 1 THEN 'day_use'
                ELSE 'overnight'
            END
            WHERE stay_type IS NULL
        ");

        // Back-fill duration_hours for existing records
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE bookings
                SET duration_hours = (julianday(check_out) - julianday(check_in)) * 24.0
                WHERE duration_hours IS NULL
            ");
        } else {
            DB::statement("
                UPDATE bookings
                SET duration_hours = TIMESTAMPDIFF(MINUTE, check_in, check_out) / 60.0
                WHERE duration_hours IS NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('check_in')->change();
            $table->date('check_out')->change();
            $table->dropColumn(['stay_type', 'duration_hours']);
        });
    }
};
