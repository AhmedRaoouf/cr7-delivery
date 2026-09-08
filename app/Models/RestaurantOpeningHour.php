<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantOpeningHour extends Model
{
    protected $fillable = ['restaurant_id', 'weekday', 'opens_at', 'closes_at', 'is_closed', 'is_24h'];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'is_24h' => 'boolean'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
