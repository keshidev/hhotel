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
            $table->foreignId('original_room_id')
                ->nullable()
                ->after('original_booking_room_id')
                ->constrained('rooms')
                ->nullOnDelete();

            $table->index('original_room_id');
        });

        // Backfill snapshot room from mapped line-item when available.
        $rows = DB::table('rebookings')
            ->whereNull('original_room_id')
            ->whereNotNull('original_booking_room_id')
            ->orderBy('id')
            ->get(['id', 'original_booking_room_id']);

        foreach ($rows as $row) {
            $roomId = DB::table('booking_rooms')
                ->where('id', $row->original_booking_room_id)
                ->value('room_id');

            if ($roomId) {
                DB::table('rebookings')
                    ->where('id', $row->id)
                    ->update(['original_room_id' => (int) $roomId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('rebookings', function (Blueprint $table) {
            $table->dropIndex(['original_room_id']);
            $table->dropForeign(['original_room_id']);
            $table->dropColumn('original_room_id');
        });
    }
};

