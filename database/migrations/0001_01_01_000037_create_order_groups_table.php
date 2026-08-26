<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_number')->unique();
            $table->string('idempotency_key')->unique();
            $table->enum('origin', ['app', 'restaurant_direct'])->default('app');
            // nullable: NULL when origin = restaurant_direct — nobody in the app owns it
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained('user_addresses')->nullOnDelete();
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('service_zone_id')->constrained('service_zones');
            $table->enum('payment_method', ['cash', 'vodafone_cash', 'instapay']);
            $table->tinyInteger('restaurant_count')->default(1);
            $table->enum('dispatch_mode', ['driver_per_restaurant', 'single_driver_multi_pickup'])
                ->default('driver_per_restaurant');
            $table->string('delivery_address_text')->nullable();
            $table->string('delivery_contact_phone')->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->decimal('max_distance_km', 8, 3)->default(0);
            $table->foreignId('delivery_pricing_rule_id')->nullable()->constrained('delivery_pricing_rules')->nullOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->decimal('delivery_base_fee', 12, 2)->default(0);
            $table->decimal('extra_stop_fee_total', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('rollup_status')->default('pending');
            $table->text('customer_note')->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmation_opened_at')->nullable();
            $table->timestamp('confirm_deadline_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->enum('confirmed_by', ['customer', 'system', 'admin'])->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['rollup_status', 'created_at']);
            $table->index(['rollup_status', 'confirm_deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_groups');
    }
};
