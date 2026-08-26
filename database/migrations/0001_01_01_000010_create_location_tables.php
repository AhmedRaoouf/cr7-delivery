<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->enum('pricing_type', ['fixed_zone', 'distance_based'])->default('fixed_zone');
            $table->decimal('default_min_order', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->json('polygon')->nullable();
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->decimal('radius_km', 8, 3)->nullable();
            $table->decimal('delivery_fee', 12, 2)->nullable();
            $table->decimal('free_delivery_over', 12, 2)->nullable();
            $table->decimal('min_order_value', 12, 2)->nullable();
            $table->integer('avg_delivery_minutes')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city_id', 'is_active']);
        });

        Schema::create('delivery_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->foreignId('service_zone_id')->nullable()->constrained('service_zones')->cascadeOnDelete();
            $table->decimal('min_distance_km', 8, 3);
            $table->decimal('max_distance_km', 8, 3);
            $table->decimal('fee', 12, 2);
            $table->decimal('free_delivery_over', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['city_id', 'service_zone_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_pricing_rules');
        Schema::dropIfExists('service_zones');
        Schema::dropIfExists('cities');
    }
};
