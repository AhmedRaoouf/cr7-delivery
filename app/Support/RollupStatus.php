<?php

namespace App\Support;

use App\Enums\OrderStatus;
use Illuminate\Support\Collection;

/** doc 04 §6.2 — order_groups.rollup_status is derived, never written by a human. Read top-down, stop at the first match. */
class RollupStatus
{
    /** @param  Collection<int, OrderStatus>  $statuses */
    public static function compute(Collection $statuses): string
    {
        $values = $statuses->map(fn (OrderStatus $s) => $s->value);

        return match (true) {
            $values->every(fn ($v) => $v === 'cancelled') => 'cancelled',
            $values->contains('delivery_issue') => 'delivery_issue',
            $values->reject(fn ($v) => $v === 'cancelled')->isNotEmpty()
                && $values->reject(fn ($v) => $v === 'cancelled')->every(fn ($v) => $v === 'delivered') => 'delivered',
            $values->contains(fn ($v) => in_array($v, ['on_the_way', 'arrived_customer'], true)) => 'on_the_way',
            $values->contains('picked_up') => 'picked_up',
            $values->contains(fn ($v) => in_array($v, ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'], true)) => 'driver_assigned',
            $values->contains(fn ($v) => in_array($v, ['ready', 'searching_driver', 'waiting_for_driver'], true)) => 'ready',
            $values->contains('preparing') => 'preparing',
            $values->contains('confirmed') => 'confirmed',
            $values->contains('awaiting_payment') => 'awaiting_payment',
            $values->contains('awaiting_customer_confirmation') => 'awaiting_customer_confirmation',
            $values->every(fn ($v) => $v === 'accepted') => 'accepted',
            default => 'pending',
        };
    }
}
