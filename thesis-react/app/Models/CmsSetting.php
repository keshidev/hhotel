<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CmsSetting extends Model
{
    public const PUBLIC_CACHE_KEY = 'cms.public.payload.v2';

    protected $fillable = ['key', 'value', 'type', 'group', 'label'];

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetPublicCache());
        static::deleted(fn () => self::forgetPublicCache());
    }

    // ── Get all settings as a flat key→value map ──────────────────────────────
    public static function getAllAsMap(): array
    {
        return self::all()->pluck('value', 'key')->toArray();
    }

    // ── Get all settings grouped ──────────────────────────────────────────────
    public static function getAllGrouped(): array
    {
        return self::all()
            ->groupBy('group')
            ->map(fn ($items) => $items->keyBy('key'))
            ->toArray();
    }

    // ── Upsert a single key ───────────────────────────────────────────────────
    public static function set(string $key, ?string $value): void
    {
        $setting = self::where('key', $key)->first();
        if ($setting) {
            $setting->value = $value;
            $setting->save();
        }
    }

    public static function forgetPublicCache(): void
    {
        Cache::forget(self::PUBLIC_CACHE_KEY);
    }
}
