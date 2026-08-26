<?php

namespace App\Models;

use App\Enums\OrderStatusActorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'from_status', 'to_status', 'actor_type', 'actor_id',
        'reason_code', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => OrderStatusActorType::class,
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
