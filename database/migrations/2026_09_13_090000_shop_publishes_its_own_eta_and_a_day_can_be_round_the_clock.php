<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts the shop should own and did not.
 *
 * **The window.** «‎20-40‎ دقيقة» on every card came out of the *zone*'s
 * `avg_delivery_minutes` ± 10, so every shop in جمصة promised the same thing and
 * neither the restaurant nor an admin could correct it. Operator, 2026-09-07:
 * «وعشرين لأربعين دقيقة ده برضه من المطعم أو الـadmin… أو ممكن نخليها رقم واحد».
 * Both ends are nullable: a shop that sets neither keeps the zone's estimate,
 * and a shop that sets them equal publishes one number rather than a range.
 *
 * **The day.** `restaurant_opening_hours` could say «closed» or «opens at … and
 * closes at …», and had no way to say «open all day». `is_24_7` on the
 * restaurant covers the whole week at once; this covers Sunday alone. Operator,
 * 2026-09-07: «برضه هتخلي كل يوم من الأيام إنها مفتوح 24 ساعة… يحدد مثلاً يوم
 * الحد 24 ساعة مفتوح، يوم الاتنين مقفول خالص».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->unsignedSmallInteger('eta_min_minutes')->nullable()->after('avg_prep_minutes');
            $table->unsignedSmallInteger('eta_max_minutes')->nullable()->after('eta_min_minutes');
        });

        Schema::table('restaurant_opening_hours', function (Blueprint $table) {
            // Distinct from `opens_at = 00:00, closes_at = 23:59`, which is what
            // the dashboard had to send to mean this and which leaves a minute
            // of the night closed.
            $table->boolean('is_24h')->default(false)->after('closes_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['eta_min_minutes', 'eta_max_minutes']);
        });

        Schema::table('restaurant_opening_hours', function (Blueprint $table) {
            $table->dropColumn('is_24h');
        });
    }
};
