<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * doc 99 §1 — D-169: a place asked for a new price and the office has not answered yet
 * (or has). The only place a requested price lives; `products` keeps the live one.
 *
 * `requested_price` / `requested_discount_price` are null when that field is not part of
 * the request; `clears_discount` asks for the discount to be removed. `option_changes` is
 * `[{option_id, group_id, option_name_ar, option_name_en, group_name_ar,
 * current_price_delta, requested_price_delta}]`.
 */
class ProductPriceRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::SUPERSEDED, self::WITHDRAWN];

    protected $fillable = [
        'product_id', 'restaurant_id', 'requested_by', 'status',
        'current_price', 'current_discount_price',
        'requested_price', 'requested_discount_price', 'clears_discount',
        'option_changes', 'note', 'reviewed_by', 'reviewed_at', 'review_note', 'superseded_by',
    ];

    /** The generated uniqueness column is the database's business, never the API's. */
    protected $hidden = ['pending_product_id'];

    protected function casts(): array
    {
        return [
            'current_price' => 'decimal:2',
            'current_discount_price' => 'decimal:2',
            'requested_price' => 'decimal:2',
            'requested_discount_price' => 'decimal:2',
            'clears_discount' => 'boolean',
            'option_changes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Whether the request touches the dish's own price or discount (not only options). */
    public function touchesProductPrice(): bool
    {
        return $this->requested_price !== null || $this->requested_discount_price !== null || $this->clears_discount;
    }

    /**
     * The shape a place reads on every product payload (`pending_price_request`).
     *
     * @return array<string, mixed>
     */
    public function toPlaceSummary(): array
    {
        return [
            'id' => $this->id,
            'requested_price' => $this->requested_price === null ? null : (float) $this->requested_price,
            'requested_discount_price' => $this->requested_discount_price === null ? null : (float) $this->requested_discount_price,
            'clears_discount' => (bool) $this->clears_discount,
            'option_changes' => array_values($this->option_changes ?? []),
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }

    /** @return array{status: string, review_note: ?string, reviewed_at: mixed} */
    public function toDecisionSummary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
        ];
    }
}
