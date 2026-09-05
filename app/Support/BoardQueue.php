<?php

namespace App\Support;

use App\Enums\OrderStatus;

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
