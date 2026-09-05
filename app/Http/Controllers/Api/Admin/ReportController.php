<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** doc 08 §7 / doc 14 — reports (scope-filtered, doc 24 §1.2). */
class ReportController extends Controller
{
    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $series = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders_count, SUM(subtotal) as gmv, SUM(commission_amount) as commission, SUM(delivery_fee_share) as delivery_fees, SUM(platform_fee_share) as platform_fees')
            ->groupBy('date')->orderBy('date')->get();

        return ApiResponse::success($series);
    }

    public function restaurants(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $restaurants = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->withCount(['orders as delivered_orders_count' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])])
            ->withSum(['orders as gmv' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])], 'subtotal')
            ->orderByDesc('gmv')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($restaurants->items(), meta: [
            'current_page' => $restaurants->currentPage(),
            'per_page' => $restaurants->perPage(),
            'total' => $restaurants->total(),
            'last_page' => $restaurants->lastPage(),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $products = Product::query()
            ->orderByDesc('sold_count')
            ->paginate($request->integer('per_page', 20), ['id', 'restaurant_id', 'name_ar', 'name_en', 'price', 'sold_count', 'rating_avg']);

        return ApiResponse::success($products->items(), meta: [
            'current_page' => $products->currentPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'last_page' => $products->lastPage(),
        ]);
    }

    public function drivers(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $drivers = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->with('user:id,name')
            ->withCount(['orders as delivered_orders_count' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])])
            ->withSum(['orders as earnings' => fn ($q) => $q->where('status', 'delivered')->whereBetween('created_at', [$from, $to])], 'driver_earning')
            ->orderByDesc('delivered_orders_count')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($drivers->items(), meta: [
            'current_page' => $drivers->currentPage(),
            'per_page' => $drivers->perPage(),
            'total' => $drivers->total(),
            'last_page' => $drivers->lastPage(),
        ]);
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
            ->get();

        return ApiResponse::success($zones);
    }

    public function promoCodes(Request $request): JsonResponse
    {
        $codes = PromoCode::query()
            ->withCount('usages')
            ->withSum('usages', 'discount_amount')
            ->orderByDesc('used_count')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($codes->items(), meta: [
            'current_page' => $codes->currentPage(),
            'per_page' => $codes->perPage(),
            'total' => $codes->total(),
            'last_page' => $codes->lastPage(),
        ]);
    }

    public function refunds(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return ApiResponse::success([
            'total_amount' => (float) Refund::query()->where('status', 'processed')->whereBetween('processed_at', [$from, $to])->sum('amount'),
            'count' => Refund::query()->where('status', 'processed')->whereBetween('processed_at', [$from, $to])->count(),
            'by_reason' => Refund::query()->whereBetween('created_at', [$from, $to])->selectRaw('reason_code, COUNT(*) as count')->groupBy('reason_code')->pluck('count', 'reason_code'),
        ]);
    }

    public function complaints(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $complaints = Complaint::query()->whereBetween('created_at', [$from, $to]);

        return ApiResponse::success([
            'total' => (clone $complaints)->count(),
            'resolved' => (clone $complaints)->where('status', 'resolved')->count(),
            'by_category' => (clone $complaints)->selectRaw('category, COUNT(*) as count')->groupBy('category')->pluck('count', 'category'),
            'avg_resolution_hours' => (clone $complaints)->whereNotNull('resolved_at')->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')->value('avg_hours'),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $segment = $request->string('filter.segment')->value();

        $customers = User::query()->where('type', 'customer');

        $customers = match ($segment) {
            'new' => $customers->where('created_at', '>=', now()->subDays(30)),
            'returning' => $customers->whereHas('orderGroups', fn ($q) => $q->where('rollup_status', 'delivered'), '>=', 2),
            'restricted' => $customers->whereNotNull('cash_only_until')->where('cash_only_until', '>', now()),
            default => $customers,
        };

        $paginated = $customers->paginate($request->integer('per_page', 20));

        return ApiResponse::success($paginated->items(), meta: [
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
            'segment' => $segment ?: 'all',
        ]);
    }

    public function customerCohorts(): JsonResponse
    {
        $cohorts = User::query()
            ->where('type', 'customer')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as cohort_month, COUNT(*) as customers_count")
            ->groupBy('cohort_month')
            ->orderBy('cohort_month')
            ->limit(6)
            ->get();

        return ApiResponse::success($cohorts);
    }

    public function opsHealth(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user());

        return ApiResponse::success([
            'orders_waiting_for_driver' => (clone $orders)->where('status', 'waiting_for_driver')->count(),
            'orders_restaurant_timeout' => (clone $orders)->where('status', 'restaurant_timeout')->count(),
            'orders_delivery_issue' => (clone $orders)->where('status', 'delivery_issue')->count(),
            'payments_under_review' => Payment::query()->where('status', 'under_review')->count(),
            'stale_drivers_online' => AdminScope::applyToDrivers(Driver::query(), $request->user())
                ->where('is_online', true)
                ->where(fn ($q) => $q->whereNull('location_updated_at')->orWhere('location_updated_at', '<', now()->subSeconds(90)))
                ->count(),
            'complaints_breached_sla' => Complaint::query()->whereNotIn('status', ['resolved', 'rejected'])->where('due_at', '<', now())->count(),
            'orders_prep_overdue' => (clone $orders)->where('status', 'preparing')->whereNotNull('prep_due_at')->where('prep_due_at', '<', now())->count(),
        ]);
    }

    private function range(Request $request): array
    {
        return [
            $request->date('filter.date_from') ?? Carbon::now()->subDays(30)->startOfDay(),
            $request->date('filter.date_to') ?? Carbon::now()->endOfDay(),
        ];
    }
}
