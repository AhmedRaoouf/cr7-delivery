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
        'cancellation_note', 'requires_refund', 'placed_at', 'accepted_at', 'accepted_by',
        'confirmed_at', 'preparing_at', 'prep_due_at', 'ready_at', 'dispatch_started_at',
        'driver_assigned_at', 'arrived_restaurant_at', 'waiting_started_at', 'picked_up_at',
        'arrived_customer_at', 'delivered_at', 'cancelled_at',
    ];

    protected $hidden = ['pickup_otp', 'delivery_otp'];

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
