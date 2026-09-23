<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasColumn = Schema::hasColumn('rooms', 'show_on_website');
        if (!$hasColumn) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->boolean('show_on_website')->default(true)->after('status');
            });
        }

        DB::table('rooms')
            ->where('room_type', 'family')
            ->update(['show_on_website' => false]);
    }

    public function down(): void
    {
        $hasColumn = Schema::hasColumn('rooms', 'show_on_website');
        if ($hasColumn) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropColumn('show_on_website');
            });
        }
    }
};
