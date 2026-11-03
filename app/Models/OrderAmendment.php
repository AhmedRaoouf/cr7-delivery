<?php

namespace App\Models;

use App\Enums\OrderAmendmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * doc 99 §4, D-172 — a change a place (or the office on its behalf) proposed to a sub-order
 * still `pending`, and the customer's answer to it.
 *
 * `lines` and `additions` are snapshots taken when it was proposed — names, pictures and
 * unit prices — so the customer approves exactly what the place wrote, and `money_before` /
 * `money_after` are the group's figures on either side of it, priced by `CheckoutPricer`.
 *
 * @property OrderAmendmentStatus $status
 */
class OrderAmendment extends Model
{
    public const PROPOSER_RESTAURANT = 'restaurant';

    public const PROPOSER_ADMIN = 'admin';

    protected $fillable = [
        'order_id', 'order_group_id', 'proposed_by', 'proposer_type', 'status', 'lines', 'additions',
        'money_before', 'money_after', 'delta', 'prep_minutes', 'restaurant_order_ref', 'note',
        'expires_at', 'responded_at', 'response_note', 'responded_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderAmendmentStatus::class,
            'lines' => 'array',
            'additions' => 'array',
            'money_before' => 'array',
            'money_after' => 'array',
            'delta' => 'decimal:2',
            'prep_minutes' => 'integer',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function isPending(): bool
    {
        return $this->status === OrderAmendmentStatus::Pending;
    }

    public function secondsLeft(): int
    {
        if (! $this->isPending() || $this->expires_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
