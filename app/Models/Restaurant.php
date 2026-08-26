<?php

namespace App\Models;

use App\Enums\MenuLayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'city_id', 'service_zone_id', 'name_ar', 'name_en',
        'description_ar', 'description_en', 'slug', 'logo', 'cover', 'phone',
        'whatsapp', 'address', 'latitude', 'longitude', 'commission_rate',
        'min_order_value', 'avg_prep_minutes', 'is_open_manual', 'is_active',
        'is_verified', 'is_featured', 'direct_orders_enabled', 'menu_layout',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'menu_layout' => MenuLayout::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'commission_rate' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'rating_avg' => 'decimal:2',
            'is_open_manual' => 'boolean',
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'direct_orders_enabled' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(Cuisine::class, 'cuisine_restaurant');
    }

    public function servedZones(): BelongsToMany
    {
        return $this->belongsToMany(ServiceZone::class, 'service_zone_restaurant');
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(RestaurantOpeningHour::class);
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }
}
