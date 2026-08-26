<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('image')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'is_active', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('menu_category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image')->nullable();
            $table->json('gallery')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('discount_price', 12, 2)->nullable();
            $table->integer('prep_minutes')->default(10);
            $table->integer('calories')->nullable();
            $table->text('ingredients_ar')->nullable();
            $table->text('ingredients_en')->nullable();
            $table->json('allergens')->nullable();
            $table->tinyInteger('spice_level')->nullable();
            $table->string('serving_size_ar')->nullable();
            $table->string('serving_size_en')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->json('tags')->nullable();
            $table->integer('sold_count')->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'menu_category_id', 'is_available', 'sort_order'], 'products_restaurant_category_status_index');
            $table->index(['restaurant_id', 'sold_count']);
            $table->index(['restaurant_id', 'rating_avg']);
            $table->fullText(['name_ar', 'name_en']);
        });

        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            $table->boolean('is_required')->default(false);
            $table->integer('min_select')->default(0);
            $table->integer('max_select')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_group_id')->constrained('product_option_groups')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_option_group_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('menu_categories');
    }
};
