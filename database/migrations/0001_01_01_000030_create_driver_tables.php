<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities');
            $table->string('national_id')->nullable();
            $table->enum('vehicle_type', ['motorcycle', 'bicycle', 'car', 'scooter'])->default('motorcycle');
            $table->string('vehicle_plate')->nullable();
            $table->enum('earning_type', ['fixed', 'per_km', 'percent_of_delivery_fee'])->default('fixed');
            $table->decimal('earning_value', 12, 2)->default(0);
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->boolean('is_online')->default(false);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            // FK added later in add_active_order_id_foreign_to_drivers_table (circular w/ orders)
            $table->unsignedBigInteger('active_order_id')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->integer('completed_orders')->default(0);
            $table->integer('cancelled_orders')->default(0);
            $table->integer('rejected_offers')->default(0);
            $table->decimal('cash_in_hand', 12, 2)->default(0);
            $table->decimal('wallet_balance', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_online', 'is_available', 'is_active', 'city_id']);
            $table->index('location_updated_at');
        });

        Schema::create('service_zone_driver', function (Blueprint $table) {
            $table->foreignId('service_zone_id')->constrained('service_zones')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->primary(['service_zone_id', 'driver_id']);
        });

        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->enum('type', ['national_id', 'license', 'vehicle_license', 'criminal_record', 'other']);
            $table->string('file');
            $table->date('expires_at')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
        Schema::dropIfExists('service_zone_driver');
        Schema::dropIfExists('drivers');
    }
};
