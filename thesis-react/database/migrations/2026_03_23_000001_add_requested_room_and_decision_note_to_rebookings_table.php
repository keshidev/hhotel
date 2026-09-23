<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rebookings', function (Blueprint $table) {
            $table->foreignId('requested_room_id')
                ->nullable()
                ->after('new_booking_id')
                ->constrained('rooms')
                ->nullOnDelete();

            $table->text('decision_note')
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('rebookings', function (Blueprint $table) {
            $table->dropForeign(['requested_room_id']);
            $table->dropColumn(['requested_room_id', 'decision_note']);
        });
    }
};
