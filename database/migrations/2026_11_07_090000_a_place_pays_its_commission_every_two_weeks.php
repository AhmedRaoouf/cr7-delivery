<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 103 — a place pays a commission on its food, and the two sides settle every 14 days.
 *
 * - `orders.place_discount` — the part of the order's discount the place pays for (a promo
 *   code whose `cost_bearer` is `restaurant`), snapshotted when the order is placed so a
 *   code edited afterwards cannot move an order already sold.
 * - `settlement_items.paid_at_pickup` / `place_discount` — each order line on a statement
 *   carries what the captain paid the place at the counter and the discount the place
 *   bore, so the line's `net` can be read without the order.
 *
 * No data is changed here. Every place stays on the rate its row holds (0 since D-75).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('place_discount', 12, 2)->default(0)->after('discount_share');
        });

        Schema::table('settlement_items', function (Blueprint $table) {
            $table->decimal('paid_at_pickup', 12, 2)->default(0)->after('commission');
            $table->decimal('place_discount', 12, 2)->default(0)->after('paid_at_pickup');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_items', function (Blueprint $table) {
            $table->dropColumn(['paid_at_pickup', 'place_discount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('place_discount');
        });
    }
};
