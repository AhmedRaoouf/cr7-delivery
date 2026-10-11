<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 87 — the operator decides the order the places are listed in.
 *
 * `restaurants.pin_position` — a place the operator pinned, and where: `1` is the very
 * first card. Every pinned place comes before every other one, in this order. The rest
 * follow `catalog.places_order` (a preset: best rated, most ordered, newest …), see
 * {@see \App\Support\Catalog\PlaceOrder}. Pinning every place is a fully manual list.
 *
 * Null for every row, so nothing moves until someone pins a place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->unsignedSmallInteger('pin_position')->nullable()->after('sort_order');
            $table->index('pin_position');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['pin_position']);
            $table->dropColumn('pin_position');
        });
    }
};
