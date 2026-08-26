<?php

namespace App\Models;

use App\Enums\AddressLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserAddress extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'city_id', 'service_zone_id', 'label', 'title', 'street',
        'building', 'floor', 'apartment', 'landmark', 'contact_phone',
        'latitude', 'longitude', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'label' => AddressLabel::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
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

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }
}
