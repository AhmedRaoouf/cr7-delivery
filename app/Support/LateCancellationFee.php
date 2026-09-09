<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderGroup;

/**
 * «رسوم الإلغاء المتأخر» — what a customer pays for cancelling a kitchen that
 * has already started.
 *
 * Operator, 2026-09-09: «الغاء الطلب ينفع طالما المطعم مبدأش تحضير، ولو عايز
 * يلغي بعد كدا لازم يحول رسوم عشرين جنيه». `OrderStatus::customerCanCancel()`
 * owns *when*; this owns *how much* and *where the money comes from*.
 *
 * **Once per cancellation, not once per restaurant.** A three-restaurant basket
 * whose kitchens have all started is still one decision the customer made and
 * one fee they were told about. Charging per sub-order would turn a 20 EGP rule
 * into a 60 EGP surprise, and the number the app shows before the tap has to be
 * the number that lands.
 *
 * **Two places it can land, and the order matters.** Netting it out of a refund
 * is the operator's chosen mechanism, so it is tried first. But the common
 * basket in Gamasa is cash, which has paid nothing and so has no refund to net
 * against — the fee becomes a balance on the account, and the next checkout
 * collects it as its own line. Nothing is written off silently.
 */
class LateCancellationFee
{
    public static function amount(): float
    {
        return round((float) Settings::get('orders.late_cancellation_fee', 20), 2);
    }

    /**
     * Does cancelling this group cost anything right now?
     *
     * True as soon as one kitchen has started. The customer is not charged
     * twice for the restaurants that had not.
     *
     * @param  iterable<Order>  $orders  the sub-orders that are about to be cancelled
     */
    public static function appliesTo(iterable $orders): bool
    {
        foreach ($orders as $order) {
            if (! $order->status->customerCancelIsFree() && $order->status->customerCanCancel()) {
                return true;
            }
        }

        return false;
    }

    /** Has this group's customer already paid, so there is a refund to net against? */
    public static function hasRefundToNetAgainst(OrderGroup $group): bool
    {
        $payment = $group->payment;

        return $payment !== null && $payment->status->value === 'paid';
    }
}
