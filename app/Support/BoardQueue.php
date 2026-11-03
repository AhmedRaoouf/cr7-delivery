<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * doc 08 §6.1 — the queue buckets the restaurant board filters on.
 *
 * This map used to live as a private constant inside
 * `Api\Restaurant\OrderController`. It moved here because the broadcast path needs
 * it too: `OrderChanged` tells a dashboard *which two columns* to refetch, and a
 * second copy of the map would be a second thing to forget when a status is added.
 */
class BoardQueue
{
    /** @var array<string, list<string>> */
    public const QUEUES = [
        'new' => ['pending', 'restaurant_timeout'],
        'awaiting_customer' => ['accepted', 'awaiting_customer_confirmation', 'awaiting_payment'],
        'confirmed' => ['confirmed'],
        'preparing' => ['preparing'],
        'ready' => ['ready', 'searching_driver', 'waiting_for_driver'],
        'with_driver' => ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant', 'picked_up', 'on_the_way', 'arrived_customer'],
        // Ahead of `done`, because `forStatus()` returns the first match and a delivered
        // order now has a column of its own. `done` stays exactly as it was so the
        // history filter and any saved link keep meaning «off the board».
        'delivered' => ['delivered'],
        'done' => ['delivered', 'cancelled', 'delivery_issue'],
    ];

    /**
     * doc 99 D-172 — the column one order sits in. A `pending` order whose place proposed
     * a change is waiting on the customer, not on the place, so it moves to
     * «بانتظار العميل» until the change is answered; a rejected or expired change puts it
     * back in «جديد», where its `amendment` block says what happened.
     */
    public static function forOrder(Order $order): string
    {
        if ($order->status === OrderStatus::Pending && $order->amendment_status === 'pending') {
            return 'awaiting_customer';
        }

        return self::forStatus($order->status);
    }

    /**
     * The board's `where` for one or more queues — the status lists above, with the one
     * exception {@see forOrder()} describes applied on both sides.
     *
     * @param  list<string>  $queues
     */
    public static function apply(Builder $query, array $queues, string $table = 'orders'): Builder
    {
        $queues = array_values(array_intersect($queues, array_keys(self::QUEUES)));

        if ($queues === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($queues, $table) {
            foreach ($queues as $queue) {
                $q->orWhere(function (Builder $one) use ($queue, $table) {
                    $one->whereIn($table.'.status', self::QUEUES[$queue]);

                    if ($queue === 'new') {
                        $one->where(fn (Builder $a) => $a->whereNull($table.'.amendment_status')->orWhere($table.'.amendment_status', '!=', 'pending'));
                    }
                });

                if ($queue === 'awaiting_customer') {
                    $q->orWhere(fn (Builder $one) => $one->where($table.'.status', OrderStatus::Pending->value)->where($table.'.amendment_status', 'pending'));
                }
            }
        });
    }

    public static function forStatus(OrderStatus|string|null $status): string
    {
        $value = $status instanceof OrderStatus ? $status->value : (string) $status;

        foreach (self::QUEUES as $queue => $statuses) {
            if (in_array($value, $statuses, true)) {
                return $queue;
            }
        }

        return 'done';
    }
}
