<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->enum('type', ['fixed', 'percent', 'free_delivery']);
            $table->decimal('value', 12, 2)->default(0);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_order_value', 12, 2)->default(0);
            $table->integer('usage_limit_total')->nullable();
            $table->integer('usage_limit_per_user')->nullable();
            $table->integer('used_count')->default(0);
            $table->boolean('first_order_only')->default(false);
            $table->json('restrict_city_ids')->nullable();
            $table->json('restrict_zone_ids')->nullable();
            $table->json('restrict_restaurant_ids')->nullable();
            $table->enum('cost_bearer', ['platform', 'restaurant'])->default('platform');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
