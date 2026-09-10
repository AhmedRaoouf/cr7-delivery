<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filter row on `ADM-04`, in one place, because two screens read it.
 *
 * `GET /admin/orders` paginates and `GET /admin/orders/export` streams the whole range,
 * and the operator's mental model is that the file is **the table they are looking at**.
 * That only holds if the two apply the same clauses — and a filter chain copied into a
 * second method is a filter chain that gains a condition on one side a release later.
 * So the list and the download share this, and a filter added here appears on both.
 *
 * Every key is read through `FiltersLists`' two spellings: `filter[restaurant_id]` is
 * what both dashboards send (doc 08 §7), and the bare `restaurant_id` is what the app's
 * console sends. Neither is going to be dropped, so both are read.
 *
 * @phpstan-type OrderBuilder Builder<\App\Models\Order>
 */
abstract class AdminOrderFilters
{
    /**
     * @param  Builder<\App\Models\Order>  $query
     * @return Builder<\App\Models\Order>
     */
    public static function apply(Builder $query, Request $request): Builder
    {
        return $query
            ->when(self::str($request, 'origin') !== '', fn ($q) => $q->where('origin', self::str($request, 'origin')))
            ->when(self::str($request, 'status') !== '', fn ($q) => $q->where('status', self::str($request, 'status')))
            // The one the operator asked for by name: «فلاتر على كل حاجة وبالأخص المطاعم».
            // A platform with forty places and one order list is unusable without it.
            ->when(self::int($request, 'restaurant_id'), fn ($q, $id) => $q->where('restaurant_id', $id))
            ->when(self::int($request, 'driver_id'), fn ($q, $id) => $q->where('driver_id', $id))
            ->when(self::int($request, 'city_id'), fn ($q, $id) => $q->where('city_id', $id))
            ->when(self::int($request, 'zone_id'), fn ($q, $id) => $q->where('service_zone_id', $id))
            ->when(self::str($request, 'payment_method') !== '', fn ($q) => $q->where('payment_method', self::str($request, 'payment_method')))
            // `whereDate` on both bounds, not `whereBetween` on the raw column: a
            // `date_to` of «2026-09-10» read as a timestamp is midnight, and every order
            // placed that day would fall outside the operator's own range.
            ->when(self::str($request, 'date_from') !== '', fn ($q) => $q->whereDate('created_at', '>=', self::str($request, 'date_from')))
            ->when(self::str($request, 'date_to') !== '', fn ($q) => $q->whereDate('created_at', '<=', self::str($request, 'date_to')))
            ->when(self::str($request, 'search') !== '', function ($q) use ($request) {
                $term = '%'.self::str($request, 'search').'%';
                $q->where(fn ($sq) => $sq
                    // `restaurant_order_ref` is in the haystack because it is the number
                    // the restaurant reads back over the phone — «طلب رقم 104 عندنا» —
                    // and until it was a column on the screen there was no way to find it.
                    ->where('order_number', 'like', $term)
                    ->orWhere('restaurant_order_ref', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            });
    }

    private static function str(Request $request, string $key): string
    {
        $scoped = trim((string) $request->input('filter.'.$key, ''));

        return $scoped !== '' ? $scoped : trim((string) $request->input($key, ''));
    }

    private static function int(Request $request, string $key): ?int
    {
        $value = self::str($request, $key);

        return $value === '' ? null : (int) $value;
    }
}
