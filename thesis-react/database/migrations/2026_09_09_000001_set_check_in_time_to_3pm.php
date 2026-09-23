<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $setting = DB::table('system_settings')->where('key', 'check_in_time');

        if ($setting->exists()) {
            $setting->update([
                'value' => json_encode('15:00'),
                'updated_at' => now(),
            ]);
            return;
        }

        DB::table('system_settings')->insert([
            'key' => 'check_in_time',
            'value' => json_encode('15:00'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')
            ->where('key', 'check_in_time')
            ->where('value', json_encode('15:00'))
            ->update([
                'value' => json_encode('14:00'),
                'updated_at' => now(),
            ]);
    }
};
