<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 78 — a group of sizes («نص كيلو», «تلت كيلو») names the price of the dish, not an
 * extra on top of it. Every group so far is an extra, so every existing row is `add` and
 * nothing a customer pays changes until a place switches a group to `final`.
 *
 * The order keeps what the choice meant on the day: `price_mode_snapshot`, and the price
 * the customer saw beside a `final` choice (`option_price_snapshot`) — whose line starts
 * from that price, so its `price_delta_snapshot` is 0 and every old reader that adds
 * `unit_price + Σ price_delta` still reaches the line total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->string('price_mode', 10)->default('add')->after('selection_type');
        });

        Schema::table('order_item_options', function (Blueprint $table) {
            $table->string('price_mode_snapshot', 10)->default('add')->after('price_delta_snapshot');
            $table->decimal('option_price_snapshot', 12, 2)->nullable()->after('price_mode_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_options', function (Blueprint $table) {
            $table->dropColumn(['price_mode_snapshot', 'option_price_snapshot']);
        });

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropColumn('price_mode');
        });
    }
};
