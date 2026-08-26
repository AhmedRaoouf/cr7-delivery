<?php

namespace App\Models;

use App\Enums\CityPricingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = [
        'name_ar', 'name_en', 'center_lat', 'center_lng', 'pricing_type',
        'default_min_order', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pricing_type' => CityPricingType::class,
            'center_lat' => 'decimal:7',
            'center_lng' => 'decimal:7',
            'default_min_order' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function serviceZones(): HasMany
    {
        return $this->hasMany(ServiceZone::class);
    }

    public function deliveryPricingRules(): HasMany
    {
        return $this->hasMany(DeliveryPricingRule::class);
    }

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }
}
