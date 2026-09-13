<?php

namespace App\Models;

use App\Enums\FoodPayment;
use App\Enums\OrderCancelledBy;
use App\Enums\OrderOrigin;
use App\Enums\OrderReadySource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_group_id', 'origin', 'created_by_restaurant_user_id', 'guest_customer_name',
        'guest_customer_phone', 'guest_customer_phone_alt', 'declared_items_text',
        'declared_order_value', 'food_payment', 'order_number', 'group_sequence',
        'idempotency_key', 'user_id', 'restaurant_id', 'driver_id', 'city_id',
        'service_zone_id', 'status', 'payment_method', 'restaurant_name_snapshot', 'customer_name_snapshot',
        'delivery_address_text', 'delivery_contact_phone', 'delivery_latitude',
        'delivery_longitude', 'distance_km', 'delivery_pricing_rule_id', 'subtotal',
        'discount_share', 'delivery_fee_share', 'platform_fee_share', 'total',
        'min_order_value_snapshot',
        'commission_rate_snapshot', 'commission_amount', 'driver_earning',
        'platform_delivery_margin', 'pickup_otp', 'pickup_otp_attempts', 'delivery_otp',
        'delivery_otp_attempts', 'delivery_otp_resends', 'restaurant_order_ref',
        'business_date', 'prep_minutes', 'prep_extended_minutes', 'prep_actual_seconds',
        'ready_source', 'dispatch_round', 'renotify_count', 'customer_note',
        'delivery_instructions', 'cancelled_by', 'cancellation_reason_code',
        'cancellation_note', 'cancellation_fee', 'requires_refund', 'placed_at', 'accepted_at', 'accepted_by',
        'confirmed_at', 'preparing_at', 'prep_due_at', 'ready_at', 'dispatch_started_at',
        'driver_assigned_at', 'arrived_restaurant_at', 'waiting_started_at', 'picked_up_at',
        'arrived_customer_at', 'delivered_at', 'cancelled_at',
        // doc 62 — the ring's schedule (D-100), the two code switches as they stood at
        // placement (D-108), why a delivery fee was waived (D-105), the address as it
        // was typed (D-101) and what the captain handed the place (D-103).
        'ring_attempts', 'ring_next_at', 'pickup_code_required', 'delivery_code_required',
        'free_delivery_reason', 'delivery_address_snapshot', 'place_paid_amount',
    ];

    protected $hidden = ['pickup_otp', 'delivery_otp'];

    /**
     * doc 04 §11.1 — the states in which the pickup OTP belongs to the restaurant.
     *
     * Before `ready` there is nothing to hand over; after the captain has quoted it
     * the code is spent. `GET /restaurant/orders/{order}/pickup-otp` has always
     * enforced exactly this list — it lives here so the board payload and the
     * endpoint cannot drift apart, which is how `RES-03` came to render «——» on
     * every ready card while the endpoint beside it returned the number.
     */
    public const PICKUP_OTP_VISIBLE_STATUSES = [
        'ready', 'searching_driver', 'waiting_for_driver',
        'driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant',
    ];

    /** Reveals `pickup_otp` on a payload bound for the restaurant that owns the order. */
    public function revealPickupOtp(): static
    {
        if (in_array($this->status->value, self::PICKUP_OTP_VISIBLE_STATUSES, true)) {
            $this->makeVisible('pickup_otp');
        }

        return $this;
    }

    /**
     * How many pieces the kitchen has to make — quantities summed, not rows.
     *
     * The captain's offer payload has published `items_count` this way since it was
     * written (`Driver\OfferController`), and the place's board had no figure at all: its
     * card counted the item rows client-side, so «صنفان» meant two lines and could be six
     * sandwiches.
     */
    public function getItemsCountAttribute(): int
    {
        return (int) ($this->relationLoaded('items')
            ? $this->items->sum('quantity')
            : $this->items()->sum('quantity'));
    }

    /**
     * What the place is owed for its own food — «حساب المكان».
     *
     * `subtotal − commission`, which is exactly what `GenerateSettlementAction` pays it
     * per item. D-75 retires the commission (every rate is 0), so today it equals the
     * subtotal; the subtraction stays because the historical orders in the table do carry
     * one, and a settlement and a board card that disagree about an old order is worse
     * than an expression with a zero in it.
     */
    public function getRestaurantAmountAttribute(): float
    {
        return round((float) $this->subtotal - (float) $this->commission_amount, 2);
    }

    /** The two figures the place's own screens and its push are entitled to. */
    public function withRestaurantMoney(): static
    {
        return $this->append(['items_count', 'restaurant_amount']);
    }

    /**
     * What the captain takes from the customer at the door, in cash.
     *
     * The sub-order's total **plus** any balance D-74 carried over from a late
     * cancellation on an earlier basket. `CheckoutPricer` adds `outstanding_fees` to the
     * *group's* total and the customer's screen shows it as its own line — but the
     * captain's card read the sub-order total, which does not contain it, so the app
     * asked for ‪20‬ ج.م the captain was never told to collect. It rides on the first
     * sub-order because a basket has one balance however many kitchens are in it.
     */
    public function cashToCollect(): float
    {
        if ($this->payment_method?->value !== 'cash') {
            return 0.0;
        }

        // A direct order's door total is its fee, plus the food when the place asked
        // the captain to collect it (D-48) — the one rule the offer card, the job screen
        // and the ledger all read.
        if ($this->origin?->value === 'restaurant_direct') {
            $food = $this->food_payment?->value === 'collect_by_driver' ? (float) $this->declared_order_value : 0.0;

            return round((float) $this->total + $food, 2);
        }

        $carried = (int) $this->group_sequence === 1
            ? (float) ($this->orderGroup?->outstanding_fees ?? 0)
            : 0.0;

        return round((float) $this->total + $carried, 2);
    }

    /**
     * D-103 — what the captain hands the place when he takes the bag.
     *
     * Operator, 2026-09-13: «الكابتن يدفع للمكان وقت الاستلام». On a cash order the
     * captain pays the place its own figure («حساب المكان») and collects the whole cash
     * total at the door; on a direct order whose food money the captain collects, he pays
     * the declared food value the same way. An online order's food is the platform's to
     * pay, in the place's statement, so the captain hands over nothing.
     *
     * A promo discount is the platform's, not the place's: the captain pays the place the
     * full figure and collects the discounted total, and the difference is what the
     * ledger records the platform owing him.
     */
    public function placePaidAtPickup(): float
    {
        if ($this->payment_method?->value !== 'cash') {
            return 0.0;
        }

        if ($this->origin?->value === 'restaurant_direct') {
            return $this->food_payment?->value === 'collect_by_driver'
                ? round((float) $this->declared_order_value, 2)
                : 0.0;
        }

        return $this->restaurant_amount;
    }

    protected function casts(): array
    {
        return [
            'origin' => OrderOrigin::class,
            'food_payment' => FoodPayment::class,
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'ready_source' => OrderReadySource::class,
            'cancelled_by' => OrderCancelledBy::class,
            'declared_order_value' => 'decimal:2',
            'delivery_latitude' => 'decimal:7',
            'delivery_longitude' => 'decimal:7',
            'distance_km' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'delivery_fee_share' => 'decimal:2',
            'platform_fee_share' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'min_order_value_snapshot' => 'decimal:2',
            'commission_rate_snapshot' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'driver_earning' => 'decimal:2',
            'platform_delivery_margin' => 'decimal:2',
            'business_date' => 'date',
            'requires_refund' => 'boolean',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'preparing_at' => 'datetime',
            'prep_due_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispatch_started_at' => 'datetime',
            'driver_assigned_at' => 'datetime',
            'arrived_restaurant_at' => 'datetime',
            'waiting_started_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'arrived_customer_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ring_next_at' => 'datetime',
            'pickup_code_required' => 'boolean',
            'delivery_code_required' => 'boolean',
            'delivery_address_snapshot' => 'array',
            'place_paid_amount' => 'decimal:2',
        ];
    }

    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    public function createdByRestaurantUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_restaurant_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    public function deliveryPricingRule(): BelongsTo
    {
        return $this->belongsTo(DeliveryPricingRule::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    public function driverAttempts(): HasMany
    {
        return $this->hasMany(OrderDriverAttempt::class);
    }

    public function deliveryIssues(): HasMany
    {
        return $this->hasMany(DeliveryIssue::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DriverLocation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DriverTransaction::class);
    }

    public function promoCodeUsages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    public function isDirect(): bool
    {
        return $this->origin === OrderOrigin::RestaurantDirect;
    }

    public function canTransitionTo(OrderStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }
}
