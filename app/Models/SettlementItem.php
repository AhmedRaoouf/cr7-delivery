<?php

namespace App\Models;

use App\Enums\SettlementItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementItem extends Model
{
    protected $fillable = [
        'settlement_id', 'order_id', 'type', 'gross', 'commission',
        'delivery_fee', 'driver_earning', 'net',
    ];

    protected function casts(): array
    {
        return [
            'type' => SettlementItemType::class,
            'gross' => 'decimal:2',
            'commission' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'driver_earning' => 'decimal:2',
            'net' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
