<?php

namespace App\Models;

use App\Enums\OrderDriverAttemptResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDriverAttempt extends Model
{
    protected $fillable = [
        'order_id', 'driver_id', 'round', 'distance_km', 'result',
        'reason_code', 'assigned_by', 'sent_at', 'expires_at', 'responded_at',
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

    /** The operator who handed this order to this captain, when it was an assignment. */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * An offer with no `expires_at` never lapses.
     *
     * Operator, 2026-09-08: «ما يكونش 30 ثانية، هو يكون موجود لحد ما يرفض أو يقبل».
     * Rows written before that ruling still carry a deadline and still honour it.
     */
    public function hasLapsed(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
