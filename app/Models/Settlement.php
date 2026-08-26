<?php

namespace App\Models;

use App\Enums\SettlementPartyType;
use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    protected $fillable = [
        'party_type', 'party_id', 'period_start', 'period_end', 'gross_sales',
        'refunds_total', 'commission_total', 'delivery_fees_total', 'cash_collected',
        'net_amount', 'status', 'payment_reference', 'note', 'created_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'party_type' => SettlementPartyType::class,
            'status' => SettlementStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_sales' => 'decimal:2',
            'refunds_total' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'delivery_fees_total' => 'decimal:2',
            'cash_collected' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** party_id points at either restaurants.id or drivers.id, picked by party_type. */
    public function party(): BelongsTo
    {
        return $this->party_type === SettlementPartyType::Restaurant
            ? $this->belongsTo(Restaurant::class, 'party_id')
            : $this->belongsTo(Driver::class, 'party_id');
    }
}
