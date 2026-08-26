<?php

namespace App\Enums;

/** doc 05 §10 — the formula `DriverEarning::for()` switches on. */
enum DriverEarningType: string
{
    case Fixed = 'fixed';
    case PerKm = 'per_km';
    case PercentOfDeliveryFee = 'percent_of_delivery_fee';
}
