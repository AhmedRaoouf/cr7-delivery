<?php

namespace App\Support;

use App\Models\Driver;
use App\Models\Order;

/** doc 05 §10 — snapshotted onto the order at assignment. */
class DriverEarningCalculator
{
    public static function calculate(Order $order, Driver $driver): float
    {
        return round(match ($driver->earning_type->value) {
            'fixed' => (float) $driver->earning_value,
            'per_km' => (float) $driver->earning_value * (float) $order->distance_km,
            'percent_of_delivery_fee' => (float) $order->delivery_fee_share * (float) $driver->earning_value / 100,
            default => 0.0,
        }, 2);
    }
}
