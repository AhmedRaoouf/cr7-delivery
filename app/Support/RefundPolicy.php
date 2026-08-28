<?php

namespace App\Support;

use App\Enums\OrderCancelledBy;
use App\Models\Order;

/**
 * D-65 — is the platform service fee refunded when an order fails?
 *
 * **When the platform failed, yes. When the customer changed their mind, no.**
 *
 * The service fee pays for running the order. If every restaurant rejected it, no
 * captain was found, the restaurant timed out, or an admin cancelled it, the platform
 * did not deliver the service and keeping the fee is indefensible. If the customer
 * cancels an order they already confirmed, the work was done and the fee stands.
 *
 * Nothing here moves money on its own: it computes the amount an admin is *shown*, and
 * the admin can still enter another one. The point is that the default is the policy,
 * not whatever number is easiest to type.
 */
class RefundPolicy
{
    /** Cancellation reasons that are the platform's own failure, not the customer's choice. */
    public const PLATFORM_FAILURE_REASONS = [
        'restaurant_rejected',
        'restaurant_timeout',
        'restaurant_closed',
        'no_driver_found',
        'driver_cancelled',
        'out_of_stock',
        'admin_cancelled',
        'delivery_issue',
        'system_error',
    ];

    /** The amount a full refund should carry, per the policy. */
    public static function fullRefundAmount(Order $order, ?string $reasonCode = null): float
    {
        $total = (float) $order->total;

        if (self::feeIsRefundable($order, $reasonCode)) {
            return round($total, 2);
        }

        // The customer walked away from an order the platform had already worked on:
        // everything back except the service fee.
        return round($total - (float) $order->platform_fee_share, 2);
    }

    public static function feeIsRefundable(Order $order, ?string $reasonCode = null): bool
    {
        if (! (bool) Settings::get('platform.order_fee_refundable_on_platform_failure', true)) {
            return false;
        }

        $reasonCode ??= $order->cancellation_reason_code;

        if ($reasonCode !== null && in_array($reasonCode, self::PLATFORM_FAILURE_REASONS, true)) {
            return true;
        }

        // An unrecognised reason is judged by who cancelled. `customer` is the only
        // actor whose cancellation is a choice rather than a failure.
        return $order->cancelled_by !== OrderCancelledBy::Customer;
    }
}
