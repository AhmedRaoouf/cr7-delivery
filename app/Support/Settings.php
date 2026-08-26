<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/** Single read path for the `settings` table (doc 03 §9) — every runtime knob lives in the DB, never in code. */
class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(
            "setting:{$key}",
            static fn () => Setting::query()->where('key', $key)->value('value') ?? $default,
        );
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
