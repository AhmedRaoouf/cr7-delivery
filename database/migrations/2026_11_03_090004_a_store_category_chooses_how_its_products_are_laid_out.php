<?php

use App\Models\StoreCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 99 §8 — D-176: a store category chooses how its places' products are laid out.
 *
 * `list` (rows, the restaurants' menu — picture on the right) or `grid` (two columns, the
 * shop shelf). The one data default of batch 99: the shops that sell goods rather than
 * dishes start on `grid`. An admin can change it on the store-category form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->string('product_layout', 10)->default('list')->after('color');
        });

        // pharmacies, supermarkets, greengrocers, gifts-surprises, cr-offers.
        StoreCategory::applyDefaultLayouts();
    }

    public function down(): void
    {
        Schema::table('store_categories', fn (Blueprint $table) => $table->dropColumn('product_layout'));
    }
};
