<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «اطلب كابتن» — a delivery with no restaurant in it.
 *
 * Operator, 2026-09-07: «عايزين نضيف خدمة داخل التطبيق أن العميل يطلب كابتن خارج خدمات
 * التطبيق، مثلاً يجيب طرد، يجيب حاجة طارئة مش موجودة على التطبيق».
 *
 * Deliberately **not** an `orders` row. Nothing in the 19-state machine applies: there is
 * no restaurant to accept, no prep countdown, no commission, no basket, no sub-orders and
 * no pro-rata anything. Forcing it into `orders` would mean nineteen states that never
 * fire and a `restaurant_id` that is always null on the busiest table in the schema — and
 * every board query would have to learn to exclude it. Five states of its own is smaller
 * and cannot break a food order.
 *
 * The fee is the customer's offer, not a computed price (operator's ruling, 2026-09-07):
 * they name what they will pay, every available captain sees it, and the first to accept
 * takes it. `suggested_fee` is what the distance would have cost on the zone table — kept
 * as a snapshot beside the offer so an operator can see, months later, how far off the
 * two were without re-deriving a fee table that has since changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('errand_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->default('open')->index();

            // Two ends, each a point plus the line a human reads. The text is stored
            // rather than re-geocoded on read: a captain riding to an address must see
            // the words the customer saw, even if the geocoder later disagrees.
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address_text', 500);
            $table->string('pickup_contact_phone', 32)->nullable();
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);
            $table->string('dropoff_address_text', 500);
            $table->string('dropoff_contact_phone', 32)->nullable();

            $table->text('details');
            $table->decimal('distance_km', 8, 3)->default(0);
            $table->decimal('offered_fee', 10, 2);
            $table->decimal('suggested_fee', 10, 2)->nullable();

            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            // `driver` when a captain took it off the board, `admin` when an operator
            // assigned it by hand after nobody did.
            $table->string('assigned_by')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // What the captain laid out of their own pocket, and the proof. Both are the
            // captain's to enter and neither is capped — operator's ruling, 2026-09-07.
            $table->decimal('purchases_amount', 10, 2)->default(0);
            $table->string('purchases_note', 500)->nullable();
            $table->json('receipt_urls')->nullable();

            $table->string('cancelled_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Set once, by the sweeper, so the escalation cannot fire twice.
            $table->timestamp('admin_notified_at')->nullable();
            $table->timestamps();

            // The dispatch board's only query: open requests, oldest first.
            $table->index(['status', 'created_at']);
            $table->index(['driver_id', 'status']);
        });

        Schema::create('errand_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('errand_request_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('actor_role')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['errand_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('errand_request_events');
        Schema::dropIfExists('errand_requests');
    }
};
