<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_group_id')->constrained('order_groups')->cascadeOnDelete();
            $table->enum('origin', ['app', 'restaurant_direct'])->default('app')->index();

            // Direct-order-only fields (FR-DIR / doc 03 §6.1)
            $table->foreignId('created_by_restaurant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_customer_name')->nullable();
            $table->string('guest_customer_phone')->nullable();
            $table->string('guest_customer_phone_alt')->nullable();
            $table->text('declared_items_text')->nullable();
            $table->decimal('declared_order_value', 12, 2)->default(0);
            $table->enum('food_payment', ['paid_at_restaurant', 'collect_by_driver'])->nullable();

            $table->string('order_number')->unique();
            $table->tinyInteger('group_sequence')->default(1);
            $table->string('idempotency_key')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants');
            // FK added later in add_driver_foreign_to_orders_table (circular w/ drivers.active_order_id)
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('service_zone_id')->constrained('service_zones');

            $table->string('status', 40)->default('pending');
            $table->enum('payment_method', ['cash', 'vodafone_cash', 'instapay']);
            $table->string('restaurant_name_snapshot');
            $table->string('delivery_address_text')->nullable();
            $table->string('delivery_contact_phone')->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->decimal('distance_km', 8, 3)->default(0);
            $table->foreignId('delivery_pricing_rule_id')->nullable()->constrained('delivery_pricing_rules')->nullOnDelete();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_share', 12, 2)->default(0);
            $table->decimal('delivery_fee_share', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('min_order_value_snapshot', 12, 2)->default(0);
            $table->decimal('commission_rate_snapshot', 5, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('driver_earning', 12, 2)->default(0);
            $table->decimal('platform_delivery_margin', 12, 2)->default(0);

            $table->string('pickup_otp', 4)->nullable();
            $table->tinyInteger('pickup_otp_attempts')->default(0);
            $table->string('delivery_otp', 4)->nullable();
            $table->tinyInteger('delivery_otp_attempts')->default(0);
            $table->tinyInteger('delivery_otp_resends')->default(0);

            $table->string('restaurant_order_ref', 32)->nullable();
            $table->date('business_date')->nullable();
            $table->integer('prep_minutes')->nullable();
            $table->integer('prep_extended_minutes')->default(0);
            $table->integer('prep_actual_seconds')->nullable();
            $table->enum('ready_source', ['restaurant', 'timer', 'admin'])->nullable();
            $table->integer('dispatch_round')->default(0);
            $table->unsignedTinyInteger('renotify_count')->default(0);

            $table->text('customer_note')->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->enum('cancelled_by', ['customer', 'restaurant', 'driver', 'admin', 'system'])->nullable();
            $table->string('cancellation_reason_code')->nullable();
            $table->text('cancellation_note')->nullable();
            $table->boolean('requires_refund')->default(false);

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('prep_due_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatch_started_at')->nullable();
            $table->timestamp('driver_assigned_at')->nullable();
            $table->timestamp('arrived_restaurant_at')->nullable();
            $table->timestamp('waiting_started_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('arrived_customer_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'status', 'created_at']);
            $table->index(['driver_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['city_id', 'service_zone_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['order_group_id', 'group_sequence']);
            $table->index(['status', 'prep_due_at']);
            $table->index(['origin', 'restaurant_id', 'created_at']);
            $table->unique(['restaurant_id', 'restaurant_order_ref', 'business_date'], 'orders_restaurant_ref_business_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
