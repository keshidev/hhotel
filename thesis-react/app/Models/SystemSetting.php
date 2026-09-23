<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();
        if (!$row) {
            return $default;
        }

        return static::decodeValue($row->value, $default);
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function readMany(array $defaults): array
    {
        $rows = static::query()
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key');

        $result = [];
        foreach ($defaults as $key => $default) {
            $result[$key] = $rows->has($key)
                ? static::decodeValue($rows[$key], $default)
                : $default;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function writeMany(array $settings): void
    {
        if (empty($settings)) {
            return;
        }

        $now = now();
        $rows = collect($settings)->map(function ($value, $key) use ($now) {
            return [
                'key' => (string) $key,
                'value' => static::encodeValue($value),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values()->all();

        static::query()->upsert($rows, ['key'], ['value', 'updated_at']);
    }

    private static function encodeValue(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeValue(?string $raw, mixed $default = null): mixed
    {
        if ($raw === null) {
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        return $decoded;
    }
}

