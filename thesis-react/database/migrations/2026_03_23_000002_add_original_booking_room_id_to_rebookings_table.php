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
            $table->foreignId('original_booking_room_id')
                ->nullable()
                ->after('original_booking_id')
                ->constrained('booking_rooms')
                ->nullOnDelete();

            $table->index(['original_booking_id', 'original_booking_room_id'], 'rebookings_original_booking_line_idx');
            $table->index(['original_booking_room_id', 'status'], 'rebookings_line_status_idx');
        });

        // Backfill legacy rows:
        // if the original booking has exactly one booking_rooms row, map that line item.
        $rows = DB::table('rebookings')
            ->whereNull('original_booking_room_id')
            ->orderBy('id')
            ->get(['id', 'original_booking_id']);

        foreach ($rows as $row) {
            $lineIds = DB::table('booking_rooms')
                ->where('booking_id', $row->original_booking_id)
                ->limit(2)
                ->pluck('id');

            if ($lineIds->count() === 1) {
                DB::table('rebookings')
                    ->where('id', $row->id)
                    ->update(['original_booking_room_id' => (int) $lineIds->first()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('rebookings', function (Blueprint $table) {
            $table->dropIndex('rebookings_original_booking_line_idx');
            $table->dropIndex('rebookings_line_status_idx');
            $table->dropForeign(['original_booking_room_id']);
            $table->dropColumn('original_booking_room_id');
        });
    }
};

