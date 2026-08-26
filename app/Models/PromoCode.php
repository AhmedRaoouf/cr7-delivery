<?php

namespace App\Models;

use App\Enums\PromoCostBearer;
use App\Enums\PromoType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'title_ar', 'title_en', 'type', 'value', 'max_discount',
        'min_order_value', 'usage_limit_total', 'usage_limit_per_user',
        'first_order_only', 'restrict_city_ids', 'restrict_zone_ids',
        'restrict_restaurant_ids', 'cost_bearer', 'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromoType::class,
            'cost_bearer' => PromoCostBearer::class,
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'first_order_only' => 'boolean',
            'restrict_city_ids' => 'array',
            'restrict_zone_ids' => 'array',
            'restrict_restaurant_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    public function orderGroups(): HasMany
    {
        return $this->hasMany(OrderGroup::class);
    }
}
