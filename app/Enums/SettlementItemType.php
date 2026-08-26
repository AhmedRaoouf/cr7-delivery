<?php

namespace App\Enums;

/**
 * doc 03 §7 — `direct_order_food_passthrough` is what stops a captain's collected
 * food money on a direct order from ever being summed into Gross sales (doc 14 §1).
 */
enum SettlementItemType: string
{
    case Order = 'order';
    case DirectOrderFoodPassthrough = 'direct_order_food_passthrough';
}
