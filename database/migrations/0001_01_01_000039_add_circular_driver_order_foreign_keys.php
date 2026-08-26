<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('driver_id')->references('id')->on('drivers')->nullOnDelete();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreign('active_order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropForeign(['active_order_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
        });
    }
};
