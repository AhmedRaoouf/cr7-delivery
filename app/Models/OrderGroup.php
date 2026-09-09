<?php

namespace App\Models;

use App\Enums\DispatchMode;
use App\Enums\OrderGroupConfirmedBy;
use App\Enums\OrderOrigin;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'group_number', 'idempotency_key', 'origin', 'user_id', 'address_id',
        'city_id', 'service_zone_id', 'payment_method', 'restaurant_count',
        'dispatch_mode', 'delivery_address_text', 'delivery_contact_phone',
        'delivery_latitude', 'delivery_longitude', 'max_distance_km',
        'delivery_pricing_rule_id', 'subtotal', 'discount_amount', 'promo_code_id',
        'delivery_base_fee', 'extra_stop_fee_total', 'delivery_fee', 'platform_fee',
        'outstanding_fees', 'total',
        'rollup_status', 'customer_note', 'delivery_instructions', 'placed_at',
        'confirmation_opened_at', 'confirm_deadline_at', 'confirmed_at', 'confirmed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'origin' => OrderOrigin::class,
            'payment_method' => PaymentMethod::class,
            'dispatch_mode' => DispatchMode::class,
            'confirmed_by' => OrderGroupConfirmedBy::class,
            'delivery_latitude' => 'decimal:7',
            'delivery_longitude' => 'decimal:7',
            'max_distance_km' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'delivery_base_fee' => 'decimal:2',
            'extra_stop_fee_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'outstanding_fees' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'confirmation_opened_at' => 'datetime',
            'confirm_deadline_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class);
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

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function isDirect(): bool
    {
        return $this->origin === OrderOrigin::RestaurantDirect;
    }
}
