<?php

namespace App\Models;

use App\Enums\DispatchMode;
use App\Enums\OrderGroupConfirmedBy;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
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
            'rating_prompt_dismissed_at' => 'datetime',
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

    /** doc 99 D-172 — the change waiting on this customer, if any. */
    public function pendingAmendment(): HasOne
    {
        return $this->hasOne(OrderAmendment::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('status', 'pending'));
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(OrderAmendment::class);
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

    /**
     * doc 102 — what the customer owes for the places still on the basket. `total` keeps a
     * cancelled place's food (it always has); the money asked for — the confirmation screen,
     * the transfer window — must not.
     *
     * @return array{subtotal: float, discount_amount: float, delivery_fee: float, platform_fee: float, outstanding_fees: float, total: float}
     */
    public function owed(): array
    {
        $live = $this->orders()->where('status', '!=', OrderStatus::Cancelled->value)
            ->get(['id', 'subtotal', 'discount_share', 'delivery_fee_share', 'platform_fee_share', 'total']);

        $outstanding = $live->isEmpty() ? 0.0 : round((float) $this->outstanding_fees, 2);

        return [
            'subtotal' => round((float) $live->sum('subtotal'), 2),
            'discount_amount' => round((float) $live->sum('discount_share'), 2),
            'delivery_fee' => round((float) $live->sum('delivery_fee_share'), 2),
            'platform_fee' => round((float) $live->sum('platform_fee_share'), 2),
            'outstanding_fees' => $outstanding,
            'total' => round((float) $live->sum('total') + $outstanding, 2),
        ];
    }
}
