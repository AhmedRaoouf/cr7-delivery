<?php

namespace App\Models;

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\RefundType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = [
        'order_id', 'payment_id', 'amount', 'type', 'reason_code', 'note',
        'status', 'method', 'reference', 'processed_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => RefundType::class,
            'status' => RefundStatus::class,
            'method' => RefundMethod::class,
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
