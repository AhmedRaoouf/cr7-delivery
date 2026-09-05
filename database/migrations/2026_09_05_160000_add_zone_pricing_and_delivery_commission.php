<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-70 / D-71 — the operator's printed price list, and the 15 % the platform keeps
 * out of every delivery fee.
 *
 * Operator, 2026-09-05, holding the company's own flyer: «المناطق كلها … العميل بيختار
 * منها، وعلى حسب الاختيار بيتحط السعر اللي جنبها» and «سعر الديليفري … الأدمين بياخد منه
 * 15% والباقي بيروح للديليفري».
 *
 * ## Why a zone stops being a circle
 *
 * The flyer names 31 areas and prices each one. Not one of them is a shape anybody has
 * drawn, and a commercial geocoder cannot place «جنة 3» or «الصناعية الرابعة» — the same
 * wall `ServiceAreaSeeder` already hit on المنصورة الجديدة. So the 31 are **priced areas
 * the customer picks**, and coverage — «are you inside Gamasa at all» — moves up to the
 * city circle, which is what `cities.radius_km` is.
 *
 * `is_selectable` is what keeps the two jobs apart. The pre-existing wide circles stay
 * active, because restaurants, addresses and `service_zone_driver` all point at them and
 * `GeoController::resolve` still reads them; they are simply no longer offered as a price.
 *
 * ## The platform's cut is already a column
 *
 * `orders.platform_delivery_margin` has been `delivery_fee_share − driver_earning` since
 * the schema was written, so the 15 % needs no new order column — only a rate that both
 * halves are computed from, and the same figure carried onto a settlement so a period can
 * be read back without re-deriving it per row.
 *
 *     delivery_fee_share = driver_earning + platform_delivery_margin
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            // Coverage. Null means «this city has no circle» — `resolve` then reads its
            // zones only, which is exactly the behaviour that shipped before.
            $table->decimal('radius_km', 8, 3)->nullable()->after('center_lng');
        });

        Schema::table('service_zones', function (Blueprint $table) {
            $table->boolean('is_selectable')->default(true)->after('is_active');
            $table->integer('sort_order')->default(0)->after('is_selectable');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('platform_delivery_margin_total', 12, 2)->default(0)->after('delivery_fees_total');
        });

        Schema::table('settlement_items', function (Blueprint $table) {
            $table->decimal('platform_delivery_margin', 12, 2)->default(0)->after('delivery_fee');
        });

        // `cash_collected` and `cash_handover` are the two halves of the money a captain
        // carries on the platform's behalf: what the customer handed them, and what they
        // handed the office. The old `cash_settlement` value stays so rows already written
        // remain readable.
        DB::statement(
            "ALTER TABLE driver_transactions MODIFY COLUMN type
             ENUM('order_earning','bonus','deduction','cash_settlement','cash_collected','cash_handover','payout') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE driver_transactions MODIFY COLUMN type
             ENUM('order_earning','bonus','deduction','cash_settlement','payout') NOT NULL"
        );

        Schema::table('settlement_items', fn (Blueprint $t) => $t->dropColumn('platform_delivery_margin'));
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn('platform_delivery_margin_total'));
        Schema::table('service_zones', fn (Blueprint $t) => $t->dropColumn(['is_selectable', 'sort_order']));
        Schema::table('cities', fn (Blueprint $t) => $t->dropColumn('radius_km'));
    }
};
