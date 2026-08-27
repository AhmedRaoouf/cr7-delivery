<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** doc 08 §7 — dashboard & alerts. */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $from = $request->date('filter.date_from') ?? Carbon::today();
        $to = $request->date('filter.date_to') ?? Carbon::now();

        $orders = AdminScope::applyToOrders(Order::query(), $request->user())->whereBetween('created_at', [$from, $to]);

        return ApiResponse::success([
            'orders_total' => (clone $orders)->count(),
            'orders_delivered' => (clone $orders)->where('status', 'delivered')->count(),
            'orders_cancelled' => (clone $orders)->where('status', 'cancelled')->count(),
            'gmv' => (float) (clone $orders)->where('status', 'delivered')->sum('subtotal'),
            'commission_total' => (float) (clone $orders)->where('status', 'delivered')->sum('commission_amount'),
            'active_orders' => (clone $orders)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'online_drivers' => AdminScope::applyToDrivers(Driver::query(), $request->user())->where('is_online', true)->count(),
        ]);
    }

    public function charts(Request $request): JsonResponse
    {
        $from = $request->date('filter.date_from') ?? Carbon::now()->subDays(7);
        $to = $request->date('filter.date_to') ?? Carbon::now();

        $orders = AdminScope::applyToOrders(Order::query(), $request->user())->whereBetween('created_at', [$from, $to]);

        $sales = (clone $orders)->where('status', 'delivered')
            ->selectRaw('DATE(created_at) as date, SUM(subtotal) as gmv, COUNT(*) as orders_count')
            ->groupBy('date')->orderBy('date')->get();

        $statuses = (clone $orders)->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $paymentMethods = (clone $orders)->selectRaw('payment_method, COUNT(*) as count')->groupBy('payment_method')->pluck('count', 'payment_method');
        $peakHours = (clone $orders)->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')->groupBy('hour')->orderBy('hour')->pluck('count', 'hour');

        return ApiResponse::success([
            'sales' => $sales,
            'statuses' => $statuses,
            'payment_methods' => $paymentMethods,
            'peak_hours' => $peakHours,
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user());

        return ApiResponse::success([
            'restaurant_timeouts' => (clone $orders)->where('status', 'restaurant_timeout')->count(),
            'no_driver' => (clone $orders)->where('status', 'waiting_for_driver')->count(),
            'delivery_issues' => (clone $orders)->where('status', 'delivery_issue')->count(),
            'payments_to_verify' => \App\Models\Payment::query()->where('status', 'under_review')->count(),
        ]);
    }

    public function liveOrders(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user())
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->with(['restaurant:id,name_ar,latitude,longitude', 'driver:id,current_lat,current_lng'])
            ->get(['id', 'order_number', 'status', 'restaurant_id', 'driver_id', 'delivery_latitude', 'delivery_longitude']);

        return ApiResponse::success($orders);
    }

    public function liveDrivers(Request $request): JsonResponse
    {
        $drivers = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->where('is_online', true)
            ->with('user:id,name')
            ->get(['id', 'user_id', 'current_lat', 'current_lng', 'location_updated_at', 'active_order_id', 'is_available']);

        return ApiResponse::success($drivers);
    }
}
