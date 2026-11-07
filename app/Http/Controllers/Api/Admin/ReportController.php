<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use App\Support\Lists\ListParams;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 / doc 14 — reports (scope-filtered, doc 24 §1.2). */
class ReportController extends Controller
{
    use FiltersLists;

    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $series = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE('.BusinessTime::sqlLocal('created_at').') as date, COUNT(*) as orders_count, SUM(subtotal) as gmv, SUM(commission_amount) as commission, SUM(delivery_fee_share) as delivery_fees, SUM(platform_fee_share) as platform_fees')
            ->groupBy('date')->orderBy('date')->get();

        return ApiResponse::success($series);
    }

    public function restaurants(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $params = $this->listParams($request);
        $sort = $params->sort(['gmv', 'delivered_orders_count', 'name'], '-gmv');

        // doc 103 — the report's columns only: whole `Restaurant` rows put every column of
        // the table on `ADM-44` as a number. The commission is the platform's per place.
        $restaurants = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->select(['restaurants.id', 'restaurants.name_ar', 'restaurants.name_en', 'restaurants.commission_rate'])
            ->withCount(['orders as delivered_orders_count' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])])
            ->withSum(['orders as gmv' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])], 'subtotal')
            ->withSum(['orders as commission' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])], 'commission_amount');

        $this->whereInList($restaurants, 'restaurants.city_id', $params->ids('city_id'));

        ListSearch::apply($restaurants, $params->search(), ['names' => ['name_ar', 'name_en'], 'id' => false]);

        $page = $sort->apply($restaurants, [
            'name' => 'restaurants.name_ar',
            'gmv' => fn ($q, string $direction) => $q->orderBy('gmv', $direction),
            'delivered_orders_count' => fn ($q, string $direction) => $q->orderBy('delivered_orders_count', $direction),
        ])->paginate($params->perPage());

        return $this->paginated($page, sort: $sort);
    }

    public function products(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['sold_count', 'rating_avg', 'price', 'name'], '-sold_count');

        // Batch 67 — every count below is the operator's scope, not the platform's
        // (doc 12 §10.2 rules 4 and 5: scope is enforced in the query).
        $products = Product::query()
            ->whereHas('restaurant', fn ($q) => AdminScope::applyToRestaurants($q, $request->user()));

        $this->whereInList($products, 'products.restaurant_id', $params->ids('restaurant_id'));

        ListSearch::apply($products, $params->search(), ['names' => ['name_ar', 'name_en'], 'id' => false]);

        $page = $sort->apply($products, ['name' => 'products.name_ar'])
            ->paginate($params->perPage(), ['id', 'restaurant_id', 'name_ar', 'name_en', 'price', 'sold_count', 'rating_avg']);

        return $this->paginated($page, sort: $sort);
    }

    public function drivers(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $params = $this->listParams($request);
        $sort = $params->sort(['delivered_orders_count', 'earnings', 'name'], '-delivered_orders_count');

        $drivers = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->with('user:id,name')
            ->withCount(['orders as delivered_orders_count' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])])
            ->withSum(['orders as earnings' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])], 'driver_earning');

        $this->whereInList($drivers, 'drivers.city_id', $params->ids('city_id'));

        ListSearch::apply($drivers, $params->search(), [
            'id' => false,
            'relations' => ['user' => ['names' => ['name'], 'phones' => ['phone']]],
        ]);

        $page = $sort->apply($drivers, [
            'name' => fn ($q, string $direction) => $q->orderBy(User::query()->select('name')->whereColumn('users.id', 'drivers.user_id'), $direction),
            'delivered_orders_count' => fn ($q, string $direction) => $q->orderBy('delivered_orders_count', $direction),
            'earnings' => fn ($q, string $direction) => $q->orderBy('earnings', $direction),
        ])->paginate($params->perPage());

        return $this->paginated($page, sort: $sort);
    }

    public function zones(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $zones = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('service_zone_id, COUNT(*) as orders_count, SUM(subtotal) as gmv')
            ->with('serviceZone:id,name_ar,name_en')
            ->groupBy('service_zone_id')
            ->get()
            // `ADM-44` reads a flat name; the app reads `service_zone`.
            ->each(fn ($row) => $row->setAttribute('zone_name', $row->serviceZone?->name_ar ?? '—'));

        return ApiResponse::success($zones);
    }

    public function promoCodes(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['used_count', 'usages_sum_discount_amount', 'code'], '-used_count');

        $codes = PromoCode::query()
            ->withCount('usages')
            ->withSum('usages', 'discount_amount');

        if (($active = $params->bool('is_active')) !== null) {
            $codes->where('is_active', $active);
        }

        ListSearch::apply($codes, $params->search(), ['refs' => ['code'], 'names' => ['title_ar', 'title_en'], 'id' => false]);

        $page = $sort->apply($codes, [
            'usages_sum_discount_amount' => fn ($q, string $direction) => $q->orderBy('usages_sum_discount_amount', $direction),
        ])->paginate($params->perPage());

        return $this->paginated($page, sort: $sort);
    }

    /**
     * `ADM-44` «المستردات». doc 103 — the screen draws a table and finds rows only under
     * `rows`, so this always read «لا توجد بيانات»; and the totals counted by
     * `processed_at` while the reasons counted by `created_at`. One date now (processed),
     * and one row per reason.
     */
    public function refunds(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $processed = fn () => AdminScope::applyToRefunds(Refund::query(), $request->user())
            ->where('status', 'processed')
            ->whereBetween('processed_at', [$from, $to]);

        $rows = $processed()
            ->selectRaw('reason_code, COUNT(*) as count, SUM(amount) as amount')
            ->groupBy('reason_code')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'reason' => (string) ($row->reason_code ?? '—'),
                'orders_count' => (int) $row->count,
                'refund_amount' => round((float) $row->amount, 2),
            ])
            ->values();

        return ApiResponse::success([
            'total_amount' => round((float) $processed()->sum('amount'), 2),
            'count' => $processed()->count(),
            'by_reason' => (object) $rows->pluck('orders_count', 'reason')->all(),
            'rows' => $rows,
        ]);
    }

    /** `ADM-44` «الشكاوى» — the same shape: totals, and one row per category. */
    public function complaints(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $complaints = AdminScope::applyToComplaints(Complaint::query(), $request->user())->whereBetween('created_at', [$from, $to]);

        $byCategory = (clone $complaints)->selectRaw('category, COUNT(*) as count')->groupBy('category')->pluck('count', 'category');
        $resolvedByCategory = (clone $complaints)->where('status', 'resolved')->selectRaw('category, COUNT(*) as count')->groupBy('category')->pluck('count', 'category');

        return ApiResponse::success([
            'total' => (clone $complaints)->count(),
            'resolved' => (clone $complaints)->where('status', 'resolved')->count(),
            'by_category' => (object) $byCategory->all(),
            'avg_resolution_hours' => (clone $complaints)->whereNotNull('resolved_at')->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')->value('avg_hours'),
            'rows' => $byCategory->map(fn ($count, $category) => [
                'category' => (string) $category,
                'total' => (int) $count,
                'resolved' => (int) ($resolvedByCategory[$category] ?? 0),
            ])->values(),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'name'], '-created_at');
        $segment = $params->enum('segment', ['new', 'returning', 'restricted'])[0] ?? null;

        $customers = AdminScope::applyToCustomers(User::query()->where('type', 'customer'), $request->user());

        // doc 103 — the screen sends a date range and counts «العملاء» under it; it was
        // never read. Joined in that range, when one is given.
        [$from, $to] = ListParams::of($request)->dates();
        $customers->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $customers = match ($segment) {
            'new' => $customers->where('created_at', '>=', BusinessTime::startOfDaysAgo(30)),
            'returning' => $customers->whereHas('orderGroups', fn ($q) => $q->where('rollup_status', 'delivered'), '>=', 2),
            'restricted' => $customers->whereNotNull('cash_only_until')->where('cash_only_until', '>', now()),
            default => $customers,
        };

        ListSearch::apply($customers, $params->search(), ['names' => ['name'], 'phones' => ['phone']]);

        $paginated = $sort->apply($customers, [])->paginate($params->perPage());

        return $this->paginated($paginated, meta: ['segment' => $segment ?: 'all'], sort: $sort);
    }

    /**
     * `ADM-44` «الاحتفاظ». doc 103 — the grid reads `cohort`, `size` and `retention` and
     * this answered `cohort_month` / `customers_count` with no retention, for the six
     * **oldest** months, by UTC month. Now: the last six Cairo months, and for each, the
     * share of that month's new customers with a delivered basket in month 0, 1, 2…
     */
    public function customerCohorts(Request $request): JsonResponse
    {
        $tz = BusinessTime::timezone();
        $firstMonth = BusinessTime::now()->startOfMonth()->subMonths(5);

        $customers = AdminScope::applyToCustomers(User::query()->where('type', 'customer'), $request->user())
            ->where('created_at', '>=', $firstMonth->copy()->utc())
            ->get(['id', 'created_at']);

        $active = OrderGroup::query()
            ->whereIn('user_id', $customers->pluck('id'))
            ->where('rollup_status', 'delivered')
            ->get(['user_id', 'created_at'])
            ->groupBy('user_id')
            ->map(fn ($groups) => $groups->map(fn ($g) => $g->created_at->copy()->setTimezone($tz)->format('Y-m'))->unique()->values()->all());

        $rows = [];
        $nowMonth = BusinessTime::now()->startOfMonth();

        for ($month = $firstMonth->copy(); $month->lessThanOrEqualTo($nowMonth); $month->addMonth()) {
            $key = $month->format('Y-m');
            $cohort = $customers->filter(fn (User $u) => $u->created_at->copy()->setTimezone($tz)->format('Y-m') === $key);
            $size = $cohort->count();
            $retention = [];

            for ($i = 0, $at = $month->copy(); $at->lessThanOrEqualTo($nowMonth); $i++, $at->addMonth()) {
                $target = $at->format('Y-m');
                $retention[] = $size === 0 ? 0.0 : round(
                    $cohort->filter(fn (User $u) => in_array($target, $active->get($u->id, []), true))->count() / $size,
                    4,
                );
            }

            $rows[] = ['cohort' => $key, 'size' => $size, 'retention' => $retention];
        }

        return ApiResponse::success($rows);
    }

    public function opsHealth(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user());

        return ApiResponse::success([
            'orders_waiting_for_driver' => (clone $orders)->where('status', 'waiting_for_driver')->count(),
            'orders_restaurant_timeout' => (clone $orders)->where('status', 'restaurant_timeout')->count(),
            'orders_delivery_issue' => (clone $orders)->where('status', 'delivery_issue')->count(),
            'payments_under_review' => AdminScope::applyToPayments(Payment::query(), $request->user())->where('status', 'under_review')->count(),
            'stale_drivers_online' => AdminScope::applyToDrivers(Driver::query(), $request->user())
                ->where('is_online', true)
                ->where(fn ($q) => $q->whereNull('location_updated_at')->orWhere('location_updated_at', '<', now()->subSeconds(90)))
                ->count(),
            'complaints_breached_sla' => AdminScope::applyToComplaints(Complaint::query(), $request->user())->whereNotIn('status', ['resolved', 'rejected'])->where('due_at', '<', now())->count(),
            'orders_prep_overdue' => (clone $orders)->where('status', 'preparing')->whereNotNull('prep_due_at')->where('prep_due_at', '<', now())->count(),
        ]);
    }

    private function range(Request $request): array
    {
        // Batch 68 — through `ListParams`, so a malformed date is a 422 rather than the
        // 500 `$request->date()` threw, and a reversed range is refused by name.
        [$from, $to] = $this->listParams($request)->dates();

        return [
            $from ?? BusinessTime::startOfDaysAgo(30),
            $to ?? BusinessTime::endOfToday(),
        ];
    }
}
