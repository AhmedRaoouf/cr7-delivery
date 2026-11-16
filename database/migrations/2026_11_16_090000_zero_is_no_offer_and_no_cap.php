<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * doc 107 — «توصيل مجاني من 0» meant «free for every basket», while the form under it
 * said «صفر يعني بلا عرض توصيل مجاني». The models now store 0 as null; this clears
 * the zeros already written. A promo cap of 0 is the same sentence («بلا حد أقصى»).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_zones')->where('free_delivery_over', '<=', 0)->update(['free_delivery_over' => null]);
        DB::table('delivery_pricing_rules')->where('free_delivery_over', '<=', 0)->update(['free_delivery_over' => null]);
        DB::table('promo_codes')->where('max_discount', '<=', 0)->update(['max_discount' => null]);
    }

    public function down(): void
    {
        // Nothing to restore: a stored 0 was never a deliberate value.
    }
};
