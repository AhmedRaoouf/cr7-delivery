<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryPricingRule extends Model
{
    protected $fillable = [
        'city_id', 'service_zone_id', 'min_distance_km', 'max_distance_km',
        'fee', 'free_delivery_over', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_distance_km' => 'decimal:3',
            'max_distance_km' => 'decimal:3',
            'fee' => 'decimal:2',
            'free_delivery_over' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }
}
