<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name_ar_snapshot');
            $table->string('product_name_en_snapshot');
            $table->string('product_image_snapshot')->nullable();
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('options_total', 12, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('line_total', 12, 2);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('product_option_id')->nullable()->constrained('product_options')->nullOnDelete();
            $table->string('group_name_snapshot');
            $table->string('option_name_ar_snapshot');
            $table->string('option_name_en_snapshot');
            $table->decimal('price_delta_snapshot', 12, 2)->default(0);
            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
    }
};
