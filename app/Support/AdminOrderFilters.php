<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Lists\ListParams;
use App\Support\Lists\ListSearch;
use App\Support\Lists\SortOrder;
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
 * Every key is read through {@see ListParams}' two spellings: `filter[restaurant_id]` is
 * what both dashboards send (doc 08 §7), and the bare `restaurant_id` is what the app's
 * console sends. Neither is going to be dropped, so both are read. ADMIN-LISTS.md §1.1 is
 * the contract.
 *
 * Batch 68 — every enum and id takes a list, the date range is a Cairo day (it was
 * `whereDate` on the UTC column, so an order placed at 01:00 Cairo was filed under the
 * previous day), and the sort is shared too, so the download comes out in the order the
 * table was showing.
 *
 * @phpstan-type OrderBuilder Builder<\App\Models\Order>
 */
abstract class AdminOrderFilters
{
    public const SORTS = ['created_at', 'id', 'total', 'delivered_at', 'status'];

    public const DEFAULT_SORT = '-created_at';

    /** `status_group` — the four chips a board filters by without naming nineteen states. */
    public const STATUS_GROUPS = [
        'active' => null,
        'completed' => ['delivered'],
        'cancelled' => ['cancelled'],
        'issue' => ['delivery_issue', 'restaurant_timeout'],
    ];

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function apply(Builder $query, Request $request): Builder
    {
        $params = ListParams::of($request);
        $statuses = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        $status = $params->enum('status', $statuses);
        $groups = $params->enum('status_group', array_keys(self::STATUS_GROUPS));
        $origin = $params->enum('origin', ['app', 'restaurant_direct'], ['direct' => 'restaurant_direct']);
        $payment = $params->enum('payment_method', ['cash', 'vodafone_cash', 'instapay']);
        $restaurants = $params->ids('restaurant_id');
        $drivers = $params->ids('driver_id', ['none']);
        $customers = $params->ids('customer_id');
        $cities = $params->ids('city_id');
        $zones = $params->ids('zone_id');
        $group = $params->id('order_group_id');
        $hasDriver = $params->bool('has_driver');
        $overdue = $params->bool('overdue');
        [$from, $to] = $params->dates();

        return $query
            ->when($status !== [], fn ($q) => $q->whereIn('orders.status', $status))
            ->when($groups !== [], fn ($q) => $q->where(function ($g) use ($groups) {
                foreach ($groups as $name) {
                    $members = self::STATUS_GROUPS[$name];
                    $members === null
                        ? $g->orWhereNotIn('orders.status', ['delivered', 'cancelled'])
                        : $g->orWhereIn('orders.status', $members);
                }
            }))
            ->when($origin !== [], fn ($q) => $q->whereIn('orders.origin', $origin))
            // The one the operator asked for by name: «فلاتر على كل حاجة وبالأخص المطاعم».
            // A platform with forty places and one order list is unusable without it.
            ->when($restaurants !== [], fn ($q) => $q->whereIn('orders.restaurant_id', $restaurants))
            ->when($drivers !== [], function ($q) use ($drivers) {
                $ids = array_values(array_filter($drivers, 'is_int'));
                $q->where(function ($d) use ($ids, $drivers) {
                    if ($ids !== []) {
                        $d->orWhereIn('orders.driver_id', $ids);
                    }
                    if (in_array('none', $drivers, true)) {
                        $d->orWhereNull('orders.driver_id');
                    }
                });
            })
            ->when($customers !== [], fn ($q) => $q->whereIn('orders.user_id', $customers))
            ->when($cities !== [], fn ($q) => $q->whereIn('orders.city_id', $cities))
            ->when($zones !== [], fn ($q) => $q->whereIn('orders.service_zone_id', $zones))
            ->when($group !== null, fn ($q) => $q->where('orders.order_group_id', $group))
            ->when($payment !== [], fn ($q) => $q->whereIn('orders.payment_method', $payment))
            ->when($hasDriver !== null, fn ($q) => $hasDriver ? $q->whereNotNull('orders.driver_id') : $q->whereNull('orders.driver_id'))
            ->when($overdue === true, fn ($q) => $q
                ->where('orders.status', 'preparing')
                ->whereNotNull('orders.prep_due_at')
                ->where('orders.prep_due_at', '<', now()))
            ->when($from, fn ($q) => $q->where('orders.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.created_at', '<=', $to))
            // `restaurant_order_ref` is in the haystack because it is the number the
            // restaurant reads back over the phone — «طلب رقم 104 عندنا» — and until it
            // was a column on the screen there was no way to find it.
            ->tap(fn ($q) => ListSearch::apply($q, $params->search(), [
                'refs' => ['order_number', 'restaurant_order_ref'],
                'names' => ['customer_name_snapshot', 'guest_customer_name'],
                'phones' => ['delivery_contact_phone', 'guest_customer_phone'],
                'relations' => ['user' => ['names' => ['name'], 'phones' => ['phone']]],
            ]));
    }

    public static function sort(Request $request): SortOrder
    {
        return ListParams::of($request)->sort(self::SORTS, self::DEFAULT_SORT);
    }
}
