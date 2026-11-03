<?php

use App\Support\Catalog\FirstPublished;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 99 §5 — D-173: a new place shows a welcome 5.0 for 14 days or 20 ratings.
 *
 * `restaurants.first_published_at` is the moment a place was first switched on. It is
 * set by the model the first time a place is saved active (`Restaurant::booted`) and is
 * never moved again: switching a place off and on, renaming it or changing its picture
 * leave it alone, so a welcome cannot be bought twice.
 *
 * **Backfill** for the places active today: {@see FirstPublished::backfill()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('first_published_at')->nullable()->after('is_active');
        });

        FirstPublished::backfill();
    }

    public function down(): void
    {
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('first_published_at'));
    }
};
