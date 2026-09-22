<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-167 — an order item's options are a snapshot, and the group they were chosen in
 * («الحجم», «التركيز») is part of what was ordered. Only the Arabic name was kept, so
 * an English order screen printed Arabic group names. Old rows are backfilled with the
 * Arabic, which is what they always showed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_options', function (Blueprint $table) {
            $table->string('group_name_en_snapshot')->nullable()->after('group_name_snapshot');
        });

        DB::table('order_item_options')->update(['group_name_en_snapshot' => DB::raw('group_name_snapshot')]);
    }

    public function down(): void
    {
        Schema::table('order_item_options', function (Blueprint $table) {
            $table->dropColumn('group_name_en_snapshot');
        });
    }
};
