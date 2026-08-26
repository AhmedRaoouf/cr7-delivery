<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('service_zone_id')->constrained('service_zones');
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('cover')->nullable();
            $table->string('phone');
            $table->string('whatsapp')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('min_order_value', 12, 2)->default(0);
            $table->integer('avg_prep_minutes')->default(20);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->integer('orders_count')->default(0);
            $table->boolean('is_open_manual')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('direct_orders_enabled')->default(false);
            $table->enum('menu_layout', ['list', 'pager'])->default('list');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['city_id', 'service_zone_id', 'is_active', 'is_open_manual'], 'restaurants_location_status_index');
            $table->index('rating_avg');
            $table->fullText(['name_ar', 'name_en']);
        });

        Schema::create('cuisine_restaurant', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('cuisine_id')->constrained('cuisines')->cascadeOnDelete();
            $table->primary(['restaurant_id', 'cuisine_id']);
        });

        Schema::create('restaurant_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->tinyInteger('weekday');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->index(['restaurant_id', 'weekday']);
        });

        Schema::create('service_zone_restaurant', function (Blueprint $table) {
            $table->foreignId('service_zone_id')->constrained('service_zones')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->primary(['service_zone_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_zone_restaurant');
        Schema::dropIfExists('restaurant_opening_hours');
        Schema::dropIfExists('cuisine_restaurant');
        Schema::dropIfExists('restaurants');
    }
};
