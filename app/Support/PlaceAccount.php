<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Collection;

/**
 * doc 103 — a place's account over a set of delivered orders, computed one way for every
 * screen that shows it: the statement (`GenerateSettlementAction`), the office's finance
 * list (`ADM-32`) and the place's own reports (`RES-20`). Three screens each summing in
 * their own way is how «مستحق» on one disagreed with the statement it produced.
 *
 * The contract (قرية الناقر, 2026-11-07, and every place after it):
 *  - the platform takes `commission_rate` % of the food (`orders.commission_amount`);
 *  - a cash order's whole food is paid to the place by the captain at the counter;
 *  - an online order's whole total is collected by the platform;
 *  - every period the side that owes pays the other.
 *
 * So, per order: earned = food − commission − the discount the place bears; the order's
 * balance = earned − what the place already took at the counter. A refund is charged to
 * the place only up to what it earned on that order — the delivery and service fees in a
 * refund are the platform's money, not the place's.
 */
final class PlaceAccount
{
    /**
     * @param  Collection<int, Order>  $orders  delivered orders of one or more places
     * @return array<string, int|float|string>
     */
    public static function of(Collection $orders): array
    {
        $isCash = fn (Order $order) => $order->payment_method?->value === 'cash';
        $marketplace = $orders->reject(fn (Order $order) => $order->isDirect());
        $online = $marketplace->reject($isCash);
        $cash = $marketplace->filter($isCash);
        $refunds = self::refunds($marketplace);

        $food = self::sum($orders, fn (Order $o) => (float) $o->subtotal);
        $commission = self::sum($orders, fn (Order $o) => (float) $o->commission_amount);
        $placeDiscount = self::sum($orders, fn (Order $o) => (float) $o->place_discount);
        $paidAtPickup = self::sum($cash, fn (Order $o) => self::paidAtPickup($o));
        $refundsTotal = self::sum($refunds, fn (float $amount) => $amount);
        $balance = round($food - $commission - $placeDiscount - $refundsTotal - $paidAtPickup, 2);

        return [
            'orders_count' => $orders->count(),
            'marketplace_orders_count' => $marketplace->count(),
            'direct_orders_count' => $orders->count() - $marketplace->count(),
            'food_total' => $food,
            'commission_total' => $commission,
            'place_discount_total' => $placeDiscount,
            'refunds_total' => $refundsTotal,
            // What the place earned: its food less the commission, its discount and refunds.
            'earned_total' => round($food - $commission - $placeDiscount - $refundsTotal, 2),

            'online_orders_count' => $online->count(),
            'online_food_total' => self::sum($online, fn (Order $o) => (float) $o->subtotal),
            'online_commission_total' => self::sum($online, fn (Order $o) => (float) $o->commission_amount),
            // What the platform owes the place on its online orders.
            'online_owed_to_place' => self::sum($online, fn (Order $o) => $o->restaurant_amount - ($refunds[$o->id] ?? 0.0)),

            'cash_orders_count' => $cash->count(),
            'cash_food_total' => self::sum($cash, fn (Order $o) => (float) $o->subtotal),
            'paid_at_pickup_total' => $paidAtPickup,
            'cash_commission_total' => self::sum($cash, fn (Order $o) => (float) $o->commission_amount),
            // What the place owes the platform on its cash orders.
            'cash_owed_by_place' => self::sum($cash, fn (Order $o) => self::paidAtPickup($o) - $o->restaurant_amount + ($refunds[$o->id] ?? 0.0)),

            // Positive: the platform pays the place. Negative: the place pays the platform.
            'balance' => $balance,
            'direction' => self::direction($balance),
        ];
    }

    /**
     * Processed refunds per order, each capped at what the place earned on that order.
     *
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, float> keyed by order id
     */
    public static function refunds(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        $byOrder = Refund::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('status', 'processed')
            ->selectRaw('order_id, SUM(amount) as amount')
            ->groupBy('order_id')
            ->pluck('amount', 'order_id');

        return $orders
            ->filter(fn (Order $order) => $byOrder->has($order->id))
            ->mapWithKeys(fn (Order $order) => [
                $order->id => round(min((float) $byOrder->get($order->id), max(0.0, $order->restaurant_amount)), 2),
            ]);
    }

    /** What the captain actually handed over, once he has; what he will, before. */
    public static function paidAtPickup(Order $order): float
    {
        return $order->place_paid_amount !== null
            ? round((float) $order->place_paid_amount, 2)
            : $order->placePaidAtPickup();
    }

    /** `platform_pays_place` · `place_pays_platform` · `settled` (nothing moves). */
    public static function direction(float $balance): string
    {
        return match (true) {
            $balance > 0.004 => 'platform_pays_place',
            $balance < -0.004 => 'place_pays_platform',
            default => 'settled',
        };
    }

    /** @param  Collection<int|string, mixed>  $items */
    private static function sum(Collection $items, callable $value): float
    {
        return round((float) $items->sum($value), 2);
    }
}
