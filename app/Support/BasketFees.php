<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * doc 102 — a basket's delivery and service fees follow the places that are still on it.
 *
 * Checkout splits the basket's one delivery fee and one service fee across its sub-orders
 * pro-rata by subtotal ({@see CheckoutPricer::shares()}). When one place was cancelled
 * nothing moved its share: CR7-2849 had two places, the second was cancelled, and the
 * customer paid 141.46 instead of 175 while the captain was paid on 39.02 of a 70 fee.
 *
 * After any sub-order is cancelled, while at least one sibling is still undelivered:
 *
 * - the basket's delivery fee is re-quoted for the places still on it — the area fee, the
 *   far-place surcharge of those places only, and the extra-stop fee for those places only.
 *   It is never raised above what checkout quoted, and a waived fee stays waived;
 * - the whole service fee stays, because it is once per basket;
 * - a delivered sibling keeps its numbers (its cash is already in), and the rest is spread
 *   over the undelivered siblings by subtotal, with the captain's earning on each re-taken;
 * - the sub-order just cancelled keeps only its food — plus, when the basket was already
 *   paid by transfer, the delivery nobody owes any more (the dropped stop), so its refund
 *   gives that back. A sibling cancelled earlier is left as it is: its refund may be out;
 * - a transfer still waiting for its proof asks for the new amount.
 *
 * Calling it twice for the same cancellation gives the same answer. It never touches the
 * discount or the carried late-cancellation fee, and a basket cancelled whole is left
 * alone ({@see self::suspended()}).
 */
class BasketFees
{
    private static int $suspended = 0;

    /**
     * Run `$work` with the rebalance switched off — for a path that cancels every sub-order
     * still on a basket in one go. One at a time, the first would hand its fees to the next
     * one about to be cancelled, and a fully cancelled basket would refund less delivery
     * than it was charged. With nobody left, the fees stay where checkout put them.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function suspended(callable $work): mixed
    {
        self::$suspended++;

        try {
            return $work();
        } finally {
            self::$suspended--;
        }
    }

    /** True when the sub-orders named are every one of the basket's still not cancelled. */
    public static function takesTheWholeBasket(OrderGroup $group, iterable $orders): bool
    {
        $ids = collect($orders)->map(fn (Order $o) => (int) $o->id)->sort()->values()->all();

        $live = $group->orders()->where('status', '!=', OrderStatus::Cancelled->value)
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $live === [] || array_diff($live, $ids) === [];
    }

    /**
     * @param  Order|null  $justCancelled  the sub-order whose cancellation this answers. Only it
     *                                     gives its fees up; a sibling cancelled earlier keeps
     *                                     what it holds, because its refund may already be out.
     */
    public static function rebalance(?OrderGroup $group, ?Order $justCancelled = null): void
    {
        if ($group === null || $group->isDirect() || self::$suspended > 0) {
            return;
        }

        DB::transaction(function () use ($group, $justCancelled) {
            $group = OrderGroup::query()->lockForUpdate()->find($group->id);

            if ($group === null) {
                return;
            }

            /** @var Collection<int, Order> $orders */
            $orders = $group->orders()->with('restaurant:id,delivery_surcharge', 'driver')
                ->orderBy('group_sequence')->orderBy('id')->lockForUpdate()->get();

            $live = $orders->reject(fn (Order $o) => $o->status === OrderStatus::Cancelled);
            $open = $live->reject(fn (Order $o) => $o->status === OrderStatus::Delivered);
            $delivered = $live->diff($open);

            $leaving = $justCancelled === null ? null : $orders->firstWhere('id', $justCancelled->id);

            if ($open->isEmpty() || $live->count() === $orders->count()
                || ($leaving !== null && $leaving->status !== OrderStatus::Cancelled)) {
                return;
            }

            // What the basket is charged for delivery now, before this cancellation.
            $charged = round((float) $orders->sum('delivery_fee_share'), 2);
            $heldElsewhere = round((float) $orders
                ->filter(fn (Order $o) => $o->status === OrderStatus::Cancelled && $o->id !== $leaving?->id)
                ->sum('delivery_fee_share'), 2);

            $delivery = self::deliveryFor($group, $orders, $live);
            $platform = round((float) $group->platform_fee, 2);

            $deliveryLeft = max(0.0, round($delivery - (float) $delivered->sum('delivery_fee_share'), 2));
            $platformLeft = max(0.0, round($platform - (float) $delivered->sum('platform_fee_share'), 2));

            $shares = app(CheckoutPricer::class)->shares(
                $open->mapWithKeys(fn (Order $o) => [$o->id => round((float) $o->subtotal, 2)]),
                0.0,
                [],
                $deliveryLeft,
                $platformLeft,
            );

            $before = round((float) $orders->sum('total'), 2);

            if ($leaving !== null) {
                // A basket already paid by transfer keeps the delivery it no longer owes —
                // the stop that is not driven any more — on the sub-order being cancelled,
                // so that sub-order's refund carries it back. Cash has collected nothing.
                $dropped = self::prepaid($group)
                    ? max(0.0, round($charged - $heldElsewhere - $delivery, 2))
                    : 0.0;
                self::write($leaving, $dropped, 0.0);
            }

            foreach ($open as $order) {
                $share = $shares[$order->id];
                self::write($order, $share['delivery_fee_share'], $share['platform_fee_share']);
            }

            $delta = round((float) $orders->sum('total') - $before, 2);

            if (abs($delta) < 0.005 && abs((float) $orders->sum('delivery_fee_share') - (float) $group->delivery_fee) < 0.005) {
                return;
            }

            $group->forceFill([
                'delivery_fee' => round((float) $orders->sum('delivery_fee_share'), 2),
                'total' => round((float) $group->total + $delta, 2),
            ])->saveQuietly();

            $payment = $group->payment()->first();

            if ($payment !== null && $payment->status === PaymentStatus::AwaitingProof) {
                $payment->update(['amount' => round((float) $group->total, 2)]);
            }
        });
    }

    /** The customer has already sent money for this basket by transfer. */
    private static function prepaid(OrderGroup $group): bool
    {
        if ($group->payment_method?->value === 'cash') {
            return false;
        }

        // The model casts `status`, so this is the enum, never the string.
        $status = $group->payment()->first()?->status;

        return in_array($status, [PaymentStatus::UnderReview, PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true);
    }

    /**
     * The delivery fee checkout would have quoted for the places still on the basket, from
     * what checkout stored: `delivery_base_fee` is the area fee plus the basket's surcharge,
     * `extra_stop_fee_total` is one stop fee per place after the first. Never more than was
     * quoted — a surcharge raised since cannot raise a placed basket.
     *
     * @param  Collection<int, Order>  $orders  every sub-order, the cancelled included
     * @param  Collection<int, Order>  $live
     */
    public static function deliveryFor(OrderGroup $group, Collection $orders, Collection $live): float
    {
        $surchargeAll = Restaurant::surchargeOf($orders->map(fn (Order $o) => $o->restaurant));
        $surchargeLive = Restaurant::surchargeOf($live->map(fn (Order $o) => $o->restaurant));

        $waived = $orders->contains(fn (Order $o) => $o->free_delivery_reason !== null);
        $base = round((float) $group->delivery_base_fee, 2);
        $stops = round((float) $group->extra_stop_fee_total, 2);

        if ($waived) {
            // A waiver leaves only the surcharge to pay (doc 97).
            $quoted = min($surchargeAll, round((float) $orders->sum('delivery_fee_share'), 2));

            return round(min($quoted, $surchargeLive), 2);
        }

        $quoted = round($base + $stops, 2);
        $perStop = $orders->count() > 1 ? $stops / ($orders->count() - 1) : 0.0;
        $area = max(0.0, $base - $surchargeAll);

        return round(min($quoted, $area + $surchargeLive + $perStop * max(0, $live->count() - 1)), 2);
    }

    private static function write(Order $order, float $delivery, float $platform): void
    {
        $total = round((float) $order->subtotal - (float) $order->discount_share + $delivery + $platform, 2);

        $columns = [
            'delivery_fee_share' => $delivery,
            'platform_fee_share' => $platform,
            'total' => $total,
        ];

        if ($order->status !== OrderStatus::Cancelled && $order->driver !== null) {
            $order->delivery_fee_share = $delivery;
            $columns['driver_earning'] = DriverEarningCalculator::calculate($order, $order->driver);
            $columns['platform_delivery_margin'] = DriverEarningCalculator::platformMargin($order, $order->driver);
        }

        $order->forceFill($columns);

        if ($order->isDirty()) {
            $order->saveQuietly();
        }
    }
}
