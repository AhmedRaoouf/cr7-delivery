<?php

namespace App\Support;

use App\Models\Driver;
use App\Models\Order;

/** doc 05 §10 — snapshotted onto the order at assignment. */
class DriverEarningCalculator
{
    /**
     * What this captain earns on this order.
     *
     * D-70 — `percent_of_delivery_fee` is the standard rule and the one every new
     * captain gets: the platform keeps {@see DeliveryCommission::rate()} of the
     * delivery fee and the captain keeps the rest. A captain whose `earning_value`
     * has never been set (0) falls to that split rather than to zero earnings — a
     * silent 0 here is a captain who rides all day for nothing and only finds out
     * on payday.
     *
     * `fixed` and `per_km` remain, because the operator can still put an individual
     * captain on one; where they do, {@see self::platformMargin()} reports whatever
     * is left of the fee, which may legitimately be negative on a long `per_km` trip.
     */
    public static function calculate(Order $order, Driver $driver): float
    {
        return round(match ($driver->earning_type->value) {
            'fixed' => (float) $driver->earning_value,
            'per_km' => (float) $driver->earning_value * (float) $order->distance_km,
            'percent_of_delivery_fee' => (float) $driver->earning_value > 0
                ? (float) $order->delivery_fee_share * (float) $driver->earning_value / 100
                : DeliveryCommission::driverShare((float) $order->delivery_fee_share),
            default => 0.0,
        }, 2);
    }

    /**
     * The platform's side of the same fee — `orders.platform_delivery_margin`.
     *
     * Always the subtraction, never a second percentage: the fee has to close
     * exactly, whatever earning rule the captain is on.
     */
    public static function platformMargin(Order $order, Driver $driver): float
    {
        return round((float) $order->delivery_fee_share - self::calculate($order, $driver), 2);
    }
}
