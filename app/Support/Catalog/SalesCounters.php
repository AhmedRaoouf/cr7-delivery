<?php

namespace App\Support\Catalog;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Providers\ListsServiceProvider;
use Illuminate\Support\Facades\DB;

/**
 * The derived sales and rating columns on the catalogue (doc 110).
 *
 * `products.sold_count`, `products.rating_avg` / `rating_count` and
 * `restaurants.orders_count` were created with the catalogue and read by the admin
 * products report (ADM-43 «مرات البيع · التقييم»), its Excel export, the menu's
 * «الأكثر طلبًا» sort and the places' «most ordered» preset — and nothing ever wrote
 * them. On 2026-11-24 production had 3,224 delivered orders and every one of its
 * 8,905 dishes read 0 sold, 0 stars.
 *
 * Every value here is **recounted** from the orders, never moved with `increment()`:
 * a status that leaves `delivered`, a rating hidden, a write that bypasses the
 * observer — a recount is right after any of them, an increment stays wrong forever.
 *
 * - `sold_count` = units sold: `SUM(order_items.quantity)` on delivered orders.
 * - A dish has no stars of its own — the customer rates the place and the captain
 *   (D-80). Its rating is the place's stars on the delivered orders that carried it,
 *   one rating per order however many lines of the dish it held, hidden ones excluded.
 * - `restaurants.orders_count` = the place's delivered orders.
 *
 * Query-builder writes: no model event, so no `PublicCache::bump()` — the catalogue's
 * 60 s TTL carries the new counts, exactly as {@see ListsServiceProvider}
 * intends for these counters.
 */
final class SalesCounters
{
    /** Recount what one order touches: its dishes and its place. */
    public static function refreshForOrder(Order $order): void
    {
        $productIds = DB::table('order_items')
            ->where('order_id', $order->id)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id')
            ->all();

        self::refreshProducts($productIds);
        self::refreshRestaurants([(int) $order->restaurant_id]);
    }

    /** @param  array<int>|null  $ids  null = every dish */
    public static function refreshProducts(?array $ids = null): int
    {
        if ($ids !== null && $ids === []) {
            return 0;
        }

        $delivered = DB::getPdo()->quote(OrderStatus::Delivered->value);

        $query = DB::table('products');

        if ($ids !== null) {
            $query->whereIn('products.id', array_values(array_unique(array_map('intval', $ids))));
        }

        // One rating per order: `in` keeps a basket with two lines of the dish to one vote.
        $rated = "from ratings r
            where r.rateable_type = 'restaurant' and r.is_hidden = 0
              and r.order_id in (
                select oi.order_id from order_items oi
                join orders o on o.id = oi.order_id
                where oi.product_id = products.id and o.status = {$delivered}
              )";

        return $query->update([
            'sold_count' => DB::raw("(select coalesce(sum(oi.quantity), 0) from order_items oi
                join orders o on o.id = oi.order_id
                where oi.product_id = products.id and o.status = {$delivered})"),
            'rating_avg' => DB::raw("(select coalesce(round(avg(r.stars), 2), 0) {$rated})"),
            'rating_count' => DB::raw("(select count(*) {$rated})"),
        ]);
    }

    /** @param  array<int>|null  $ids  null = every place */
    public static function refreshRestaurants(?array $ids = null): int
    {
        if ($ids !== null && $ids === []) {
            return 0;
        }

        $query = DB::table('restaurants');

        if ($ids !== null) {
            $query->whereIn('restaurants.id', array_values(array_unique(array_map('intval', $ids))));
        }

        $delivered = DB::getPdo()->quote(OrderStatus::Delivered->value);

        return $query->update([
            'orders_count' => DB::raw("(select count(*) from orders o where o.restaurant_id = restaurants.id and o.status = {$delivered})"),
        ]);
    }
}
