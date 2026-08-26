<?php

namespace App\Models;

use App\Enums\DriverEarningType;
use App\Enums\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'city_id', 'national_id', 'vehicle_type', 'vehicle_plate',
        'earning_type', 'earning_value', 'current_lat', 'current_lng',
        'location_updated_at', 'is_online', 'is_available', 'is_active',
        'active_order_id',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'earning_type' => DriverEarningType::class,
            'earning_value' => 'decimal:2',
            'current_lat' => 'decimal:7',
            'current_lng' => 'decimal:7',
            'location_updated_at' => 'datetime',
            'is_online' => 'boolean',
            'is_available' => 'boolean',
            'is_active' => 'boolean',
            'rating_avg' => 'decimal:2',
            'cash_in_hand' => 'decimal:2',
            'wallet_balance' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function assignedZones(): BelongsToMany
    {
        return $this->belongsToMany(ServiceZone::class, 'service_zone_driver');
    }

    public function activeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'active_order_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DriverLocation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DriverTransaction::class);
    }

    public function driverAttempts(): HasMany
    {
        return $this->hasMany(OrderDriverAttempt::class);
    }

    /** doc 05 §2 / FR-DRV-20: online, available and a fresh-enough location ping. */
    public function isLive(int $staleSeconds = 90): bool
    {
        return $this->is_online
            && $this->is_available
            && $this->is_active
            && $this->location_updated_at !== null
            && $this->location_updated_at->diffInSeconds(now()) < $staleSeconds;
    }
}
