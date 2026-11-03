<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * doc 99 §5 — D-173: when each place already on the app was first published, for the
 * migration that adds `restaurants.first_published_at`.
 *
 * Only places active today are filled (a place never published stays null):
 *
 * 1. the earliest admin activation in `audit_logs` (`restaurants.created` /
 *    `restaurants.updated` whose `new_values.is_active` is true), else `created_at`;
 * 2. then the earlier of that and the place's first order — an order is proof the place
 *    was already on the app, which keeps an established place that an admin switched off
 *    and on again from being read as new.
 *
 * Idempotent: a place that already has the column set is left alone.
 */
final class FirstPublished
{
    public static function backfill(): int
    {
        $activations = DB::table('audit_logs')
            ->whereIn('action', ['restaurants.created', 'restaurants.updated'])
            ->where('auditable_type', 'App\\Models\\Restaurant')
            ->whereRaw("json_unquote(json_extract(new_values, '$.is_active')) in ('true', '1')")
            ->groupBy('auditable_id')
            ->selectRaw('auditable_id, min(created_at) as at')
            ->pluck('at', 'auditable_id');

        $firstOrders = DB::table('orders')
            ->groupBy('restaurant_id')
            ->selectRaw('restaurant_id, min(created_at) as at')
            ->pluck('at', 'restaurant_id');

        $filled = 0;

        DB::table('restaurants')
            ->where('is_active', true)
            ->whereNull('first_published_at')
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function (object $place) use ($activations, $firstOrders, &$filled): void {
                $at = $activations[$place->id] ?? $place->created_at;

                if (isset($firstOrders[$place->id]) && ($at === null || $firstOrders[$place->id] < $at)) {
                    $at = $firstOrders[$place->id];
                }

                DB::table('restaurants')->where('id', $place->id)->update(['first_published_at' => $at ?? now()]);
                $filled++;
            });

        return $filled;
    }
}
