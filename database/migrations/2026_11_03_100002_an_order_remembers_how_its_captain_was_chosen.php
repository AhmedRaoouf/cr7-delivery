<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * doc 99 §3 — D-171. `accept` (a captain pressed «قبول»), `basket` (D-115's holder),
     * `office` (an operator) or `auto` (the nearest captain, chosen by the server).
     * Written by `TripAssignment::assign` on every path; null for orders assigned before
     * this column existed.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('assigned_via', 16)->nullable()->after('driver_assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('assigned_via'));
    }
};
