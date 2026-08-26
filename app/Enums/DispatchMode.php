<?php

namespace App\Enums;

/** doc 05 §13 — per-order-group dispatch strategy. */
enum DispatchMode: string
{
    case DriverPerRestaurant = 'driver_per_restaurant';
    case SingleDriverMultiPickup = 'single_driver_multi_pickup';
}
