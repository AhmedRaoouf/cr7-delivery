<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-63 — the platform service fee: a fixed amount the platform charges the customer
 * on top of the food and on top of the delivery fee, and which belongs to the platform
 * owner rather than to the restaurant or the captain.
 *
 *     total = subtotal − discount_amount + delivery_fee + platform_fee
 *
 * Charged **once per order group** (D-63), exactly like the delivery fee: a basket
 * spanning three restaurants pays it once, not three times. The per-order column is a
 * pro-rata share so the sub-order rows still add back to the group total.
 *
 * Every column is a snapshot. Changing `platform.order_fee` later must never re-price
 * an order already placed — the same reason `commission_rate_snapshot` exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_groups', function (Blueprint $table) {
            $table->decimal('platform_fee', 12, 2)->default(0)->after('delivery_fee');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('platform_fee_share', 12, 2)->default(0)->after('delivery_fee_share');
        });

        // Reported, never paid out — what the platform kept in the period. It is
        // deliberately outside `net_amount` for both party types.
        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('platform_fee_total', 12, 2)->default(0)->after('delivery_fees_total');
        });

        Schema::table('settlement_items', function (Blueprint $table) {
            $table->decimal('platform_fee', 12, 2)->default(0)->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_items', fn (Blueprint $table) => $table->dropColumn('platform_fee'));
        Schema::table('settlements', fn (Blueprint $table) => $table->dropColumn('platform_fee_total'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('platform_fee_share'));
        Schema::table('order_groups', fn (Blueprint $table) => $table->dropColumn('platform_fee'));
    }
};
