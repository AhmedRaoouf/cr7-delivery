<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'order_group_id', 'user_id', 'method', 'provider', 'status', 'amount',
        'collected_amount', 'provider_transaction_id', 'provider_payload', 'reference',
        'verified_by', 'verified_at', 'paid_at', 'gate_opened_at', 'deadline_at',
        'reminder_sent', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'provider_payload' => 'array',
            'verified_at' => 'datetime',
            'paid_at' => 'datetime',
            'gate_opened_at' => 'datetime',
            'deadline_at' => 'datetime',
            'reminder_sent' => 'boolean',
        ];
    }

    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
