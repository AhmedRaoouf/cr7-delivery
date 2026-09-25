<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 82 — the cuisine filter («برجر، بيتزا، حلويات، مشويات») is a property of the
 * **dish**, not of the place.
 *
 * Until now a cuisine was tagged on a restaurant (`cuisine_restaurant`), and tapping
 * «برجر» listed every dish of every place tagged «برجر» — its drinks and its sauces
 * included — while a burger at a place tagged «مشويات» never appeared.
 *
 * - `products.cuisine_id` — the one cuisine a dish belongs to. Nullable at the column
 *   so an old client that sends none still saves; the model fills it
 *   ({@see \App\Support\Catalog\CuisineClassifier}) and falls back to «أخرى».
 * - `cuisines.is_fallback` — exactly one row, «أخرى», the home of every dish no
 *   filter fits. It is never offered to the customer as a filter.
 * - `cuisines.restaurant_id` — the place that created the cuisine from its own
 *   product form, when a place did. Informational: the cuisine is shared by all.
 *
 * The existing dishes are filed by `php artisan cr7:classify-products`, run by hand
 * after this migration (a data write with a dry run in front of it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuisines', function (Blueprint $table) {
            $table->boolean('is_fallback')->default(false)->after('is_active');
            $table->foreignId('restaurant_id')->nullable()->after('is_fallback')
                ->constrained('restaurants')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('cuisine_id')->nullable()->after('menu_category_id')
                ->constrained('cuisines')->nullOnDelete();
        });

        if (! DB::table('cuisines')->where('is_fallback', true)->exists()) {
            DB::table('cuisines')->insert([
                'name_ar' => 'أخرى',
                'name_en' => 'Other',
                'icon' => 'other',
                'sort_order' => 9999,
                'is_active' => true,
                'is_fallback' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuisine_id');
        });

        DB::table('cuisines')->where('is_fallback', true)->delete();

        Schema::table('cuisines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_id');
            $table->dropColumn('is_fallback');
        });
    }
};
