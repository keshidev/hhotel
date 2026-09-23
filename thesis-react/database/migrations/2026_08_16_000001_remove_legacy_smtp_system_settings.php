<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_KEYS = [
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
    ];

    public function up(): void
    {
        DB::table('system_settings')->whereIn('key', self::LEGACY_KEYS)->delete();
    }

    public function down(): void
    {
        $now = now();
        $defaults = [
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_encryption' => 'tls',
            'smtp_username' => '',
        ];

        $rows = collect($defaults)->map(fn ($value, $key) => [
            'key' => $key,
            'value' => json_encode($value),
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('system_settings')->upsert($rows, ['key'], ['value', 'updated_at']);
    }
};
