<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `dispatch.offer_to_all_online` asks captains who have never reported a position, and
 * there is no distance to write for them. The column defaulted to `0`, which is not
 * «unknown» — it is «standing in the restaurant's doorway», and it sorted such a captain
 * ahead of the one actually nearby.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_driver_attempts', function (Blueprint $table) {
            $table->decimal('distance_km', 8, 3)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_driver_attempts', function (Blueprint $table) {
            $table->decimal('distance_km', 8, 3)->nullable(false)->default(0)->change();
        });
    }
};
