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

    // doc 62 §4.4 — a place's statement says which way each order's money went.
    case Online = 'online';
    case CashPaidAtPickup = 'cash_paid_at_pickup';
    case Direct = 'direct';

    // doc 62 §4.3 — a captain's statement lists the orders it closed.
    case DriverCashOrder = 'driver_cash_order';
    case DriverOnlineOrder = 'driver_online_order';
}
