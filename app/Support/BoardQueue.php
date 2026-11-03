<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

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
     * doc 99 §3 — the statuses an order has once a captain carries it and before he has
     * collected it. With `ready_at` still null and a countdown running, the food is still
     * in the kitchen: the card stays on «قيد التحضير».
     */
    public const KITCHEN_PENDING_STATUSES = ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'];

    /** On a captain's trip, the kitchen still cooking (doc 99 §3, «the kitchen is not the captain»). */
    public static function kitchenPending(Order $order): bool
    {
        $status = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

        return in_array($status, self::KITCHEN_PENDING_STATUSES, true)
            && $order->ready_at === null
            && $order->prep_due_at !== null;
    }

    /**
     * The column a card belongs in. A `pending` order whose place proposed a change waits
     * on the customer (doc 99 D-172) and sits in «بانتظار العميل» until it is answered; a
     * rejected or expired change puts it back in «جديد». An order a captain carries while
     * its food is still cooking stays in «قيد التحضير» (doc 99 §3). Otherwise its status.
     */
    public static function forOrder(Order $order): string
    {
        if ($order->status === OrderStatus::Pending && $order->amendment_status === 'pending') {
            return 'awaiting_customer';
        }

        return self::kitchenPending($order) ? 'preparing' : self::forStatus($order->status);
    }

    /**
     * Narrow a query to the union of these queues. `preparing` also holds the cards whose
     * captain is already assigned while the kitchen cooks; `with_driver` does not; `new`
     * leaves out an order whose proposed change waits on the customer, which
     * `awaiting_customer` holds instead.
     *
     * @param  Builder<Order>|Relation<Order>  $query
     * @param  list<string>  $queues
     * @return Builder<Order>|Relation<Order>
     */
    public static function apply(Builder|Relation $query, array $queues, string $table = 'orders'): Builder|Relation
    {
        $queues = array_values(array_intersect($queues, array_keys(self::QUEUES)));

        if ($queues === []) {
            return $query;
        }

        $query->where(function ($any) use ($queues, $table) {
            foreach ($queues as $queue) {
                $statuses = self::QUEUES[$queue];

                $any->orWhere(function ($one) use ($queue, $statuses, $table) {
                    $one->whereIn($table.'.status', $statuses);

                    if ($queue === 'with_driver') {
                        $one->whereNot(fn ($k) => self::whereKitchenPending($k));
                    }

                    if ($queue === 'new') {
                        $one->where(fn ($a) => $a->whereNull($table.'.amendment_status')->orWhere($table.'.amendment_status', '!=', 'pending'));
                    }
                });

                if ($queue === 'preparing') {
                    $any->orWhere(fn ($k) => self::whereKitchenPending($k));
                }

                if ($queue === 'awaiting_customer') {
                    $any->orWhere(fn ($one) => $one->where($table.'.status', OrderStatus::Pending->value)->where($table.'.amendment_status', 'pending'));
                }
            }
        });

        return $query;
    }

    /** @param Builder<Order>|\Illuminate\Database\Query\Builder $query */
    public static function whereKitchenPending($query): void
    {
        $query->whereIn('orders.status', self::KITCHEN_PENDING_STATUSES)
            ->whereNull('orders.ready_at')
            ->whereNotNull('orders.prep_due_at');
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
