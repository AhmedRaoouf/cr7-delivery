<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceZone extends Model
{
    protected $fillable = [
        'city_id', 'name_ar', 'name_en', 'polygon', 'center_lat', 'center_lng',
        'radius_km', 'delivery_fee', 'free_delivery_over', 'min_order_value',
        'avg_delivery_minutes', 'is_active', 'is_selectable', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'polygon' => 'array',
            'center_lat' => 'decimal:7',
            'center_lng' => 'decimal:7',
            'radius_km' => 'decimal:3',
            'delivery_fee' => 'decimal:2',
            'free_delivery_over' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'is_active' => 'boolean',
            'is_selectable' => 'boolean',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function deliveryPricingRules(): HasMany
    {
        return $this->hasMany(DeliveryPricingRule::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'service_zone_restaurant');
    }

    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'service_zone_driver');
    }
}
