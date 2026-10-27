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

    /**
     * doc 96 — the places this code is limited to, as ints; empty means every place.
     * The column is JSON written by more than one client, so a `"5"` and a `5` are the
     * same place here.
     *
     * @return list<int>
     */
    public function restaurantIds(): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter((array) ($this->restrict_restaurant_ids ?? []), 'is_numeric'),
        )));
    }

    public function appliesToRestaurant(int $restaurantId): bool
    {
        $ids = $this->restaurantIds();

        return $ids === [] || in_array($restaurantId, $ids, true);
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
