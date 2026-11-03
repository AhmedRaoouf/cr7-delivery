<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * doc 99 §5 — D-173: a new place shows a welcome 5.0 for 14 days or 20 ratings.
 *
 * `restaurants.first_published_at` is the moment a place was first switched on. It is
 * set by the model the first time a place is saved active (`Restaurant::booted`) and is
 * never moved again: switching a place off and on, renaming it or changing its picture
 * leave it alone, so a welcome cannot be bought twice.
 *
 * **Backfill**, for the places active today only (a place never published stays null):
 *
 * 1. the earliest admin activation in `audit_logs` (`restaurants.created` /
 *    `restaurants.updated` whose `new_values.is_active` is true), else `created_at`;
 * 2. then the earlier of that and the place's first order — an order is proof the place
 *    was already on the app, which keeps an established place that was switched off and
 *    on again by an admin from being read as new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('first_published_at')->nullable()->after('is_active');
        });

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

        DB::table('restaurants')
            ->where('is_active', true)
            ->whereNull('first_published_at')
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function (object $place) use ($activations, $firstOrders): void {
                $at = $activations[$place->id] ?? $place->created_at;

                if (isset($firstOrders[$place->id]) && ($at === null || $firstOrders[$place->id] < $at)) {
                    $at = $firstOrders[$place->id];
                }

                DB::table('restaurants')->where('id', $place->id)->update(['first_published_at' => $at ?? now()]);
            });
    }

    public function down(): void
    {
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('first_published_at'));
    }
};
