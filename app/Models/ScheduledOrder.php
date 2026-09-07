<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A basket with a time on it.
 *
 * Deliberately not an `orders` row in a twentieth state. An order that exists is one a
 * restaurant can accept, a captain can be offered, a promo code can be spent against and
 * the confirmation timer can start counting — none of which may happen at 14:00 for an
 * 18:00 delivery. So the checkout body is kept whole here and the real
 * `PlaceOrderAction` runs at the appointed minute, against the prices and the stock that
 * are true then.
 *
 * `basket_snapshot` is the readable half: what the customer picked, at the price they
 * were quoted, so the dashboards and «طلباتي المجدولة» can show the order without
 * re-pricing anything.
 */
class ScheduledOrder extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_RELEASED = 'released';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reference', 'user_id', 'address_id', 'scheduled_for', 'status', 'payload',
        'basket_snapshot', 'estimated_total', 'restaurant_count', 'order_group_id',
        'released_at', 'failure_code', 'failure_message', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'released_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'payload' => 'array',
            'basket_snapshot' => 'array',
            'estimated_total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }

    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    /**
     * The restaurants this basket will land on.
     *
     * Denormalised into its own pivot rather than dug out of `basket_snapshot` on every
     * read: the restaurant dashboard's «مجدولة» queue is a `whereHas` on this, and a JSON
     * search would take the whole table with it.
     */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'scheduled_order_restaurants');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }
}
