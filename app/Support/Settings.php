<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/** Single read path for the `settings` table (doc 03 §9) — every runtime knob lives in the DB, never in code. */
class Settings
{
    /**
     * The stored value, or `$default` when nothing is stored.
     *
     * **The cache holds the stored value, never the caller's default.** It used to hold
     * whatever the first caller computed — `$value ?? $default` inside the closure — so on
     * an unwritten key the answer for the whole process was decided by whichever reader
     * happened to warm the entry first. Two readers of one key with different literal
     * fallbacks (and there were some) then disagreed by boot order rather than by anything
     * in the row, and nothing about the row changed to explain it. The default is applied
     * after the lookup now, so it belongs to the caller and not to the cache.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            "setting:{$key}",
            static fn () => Setting::query()->where('key', $key)->value('value'),
        );

        return $value ?? $default;
    }

    public static function set(string $key, mixed $value, ?string $group = null, bool $isPublic = false): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'is_public' => $isPublic],
        );

        Cache::forget("setting:{$key}");
    }

    public static function forget(string $key): void
    {
        Cache::forget("setting:{$key}");
    }
}
