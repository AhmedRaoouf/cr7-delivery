<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * doc 97 — a place outside the town the customers live in (New Damietta, ordered
     * from Gamasa) adds a fixed amount on top of the customer's area fee.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('delivery_surcharge', 8, 2)->default(0)->after('min_order_value');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('delivery_surcharge'));
    }
};
