<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * doc 63 — 2026-09-15. No column changes: the trip is derived from `orders` (D-117).
 *
 * - `dispatch.mode_multi_restaurant` is deleted. It was seeded `driver_per_restaurant`
 *   since day one and read by nothing. D-115's switch is a new key,
 *   `dispatch.one_captain_per_basket`, so a stale row here could never have left
 *   production on D-30 — but a row that documents behaviour the code does not have is how
 *   `dispatch.allow_neighbour_zones` misled an operator for a month (doc 61).
 * - The four new keys are **not** inserted: a catalogue key with no row reads its declared
 *   default through `SettingsCatalog::get`, exactly as doc 62's were.
 * - Every captain's `active_order_id` / `is_available` is left as it is. The first write
 *   that moves any of their orders re-derives both, and `POST /driver/status` does too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'dispatch.mode_multi_restaurant')->delete();

        Cache::forget('setting:dispatch.mode_multi_restaurant');
    }

    public function down(): void
    {
        if (DB::table('settings')->where('key', 'dispatch.mode_multi_restaurant')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'dispatch.mode_multi_restaurant',
            'value' => json_encode('driver_per_restaurant'),
            'group' => 'dispatch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
