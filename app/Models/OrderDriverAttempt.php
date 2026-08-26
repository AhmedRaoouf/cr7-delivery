<?php

namespace App\Models;

use App\Enums\OrderDriverAttemptResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDriverAttempt extends Model
{
    protected $fillable = [
        'order_id', 'driver_id', 'round', 'distance_km', 'result',
        'reason_code', 'sent_at', 'expires_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => OrderDriverAttemptResult::class,
            'distance_km' => 'decimal:3',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
