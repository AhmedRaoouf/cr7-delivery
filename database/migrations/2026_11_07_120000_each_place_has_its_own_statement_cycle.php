<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * doc 104 — the automatic statement is for a place on a commission, on its own cycle.
 *
 * - `restaurants.settlement_starts_on` — the first day of the place's first period: the
 *   day its contract starts. Written by `Restaurant::booted()` when a rate first goes
 *   above zero, cleared when it goes back to zero.
 * - `restaurants.settlement_every_days` — the length of each period; null follows
 *   `settlements.cycle_days`.
 *
 * A place already on a rate keeps the cycle it had: periods from 2026-11-07, 14 days
 * (doc 103 — قرية الناقر is the only one). `settlements.cycle_start` goes; each place
 * carries its own start now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->date('settlement_starts_on')->nullable()->after('commission_rate');
            $table->unsignedSmallInteger('settlement_every_days')->nullable()->after('settlement_starts_on');
        });

        DB::table('restaurants')->where('commission_rate', '>', 0)
            ->update(['settlement_starts_on' => '2026-11-07', 'settlement_every_days' => 14]);

        DB::table('settings')->where('key', 'settlements.cycle_start')->delete();
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['settlement_starts_on', 'settlement_every_days']);
        });
    }
};
