<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Payment;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** doc 08 §7 — dashboard & alerts. */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        // «اليوم» is a Cairo day, not a UTC one — see `BusinessTime`.
        $from = BusinessTime::fromInput($request->date('filter.date_from')) ?? BusinessTime::startOfToday();
        $to = BusinessTime::fromInput($request->date('filter.date_to'), endOfDay: true) ?? Carbon::now();

        $orders = AdminScope::applyToOrders(Order::query(), $request->user())->whereBetween('created_at', [$from, $to]);

        return ApiResponse::success([
            'orders_total' => (clone $orders)->count(),
            'orders_delivered' => (clone $orders)->where('status', 'delivered')->count(),
            'orders_cancelled' => (clone $orders)->where('status', 'cancelled')->count(),
            'gmv' => (float) (clone $orders)->where('status', 'delivered')->sum('subtotal'),
            'commission_total' => (float) (clone $orders)->where('status', 'delivered')->sum('commission_amount'),
            // D-63 — platform revenue is now two numbers. Anywhere a single "revenue"
            // figure is printed it has to be commission_total + platform_fees_total.
            'platform_fees_total' => (float) (clone $orders)->where('status', 'delivered')->sum('platform_fee_share'),
            'platform_revenue_total' => (float) (clone $orders)->where('status', 'delivered')->sum('commission_amount')
                + (float) (clone $orders)->where('status', 'delivered')->sum('platform_fee_share'),
            'active_orders' => (clone $orders)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'online_drivers' => AdminScope::applyToDrivers(Driver::query(), $request->user())->where('is_online', true)->count(),
        ]);
    }

    public function charts(Request $request): JsonResponse
    {
        $from = BusinessTime::fromInput($request->date('filter.date_from')) ?? BusinessTime::startOfDaysAgo(7);
        $to = BusinessTime::fromInput($request->date('filter.date_to'), endOfDay: true) ?? Carbon::now();

        $orders = AdminScope::applyToOrders(Order::query(), $request->user())->whereBetween('created_at', [$from, $to]);

        $localCreatedAt = BusinessTime::sqlLocal('created_at');

        $sales = (clone $orders)->where('status', 'delivered')
            ->selectRaw("DATE({$localCreatedAt}) as date, SUM(subtotal) as gmv, COUNT(*) as orders_count")
            ->groupBy('date')->orderBy('date')->get();

        $statuses = (clone $orders)->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $paymentMethods = (clone $orders)->selectRaw('payment_method, COUNT(*) as count')->groupBy('payment_method')->pluck('count', 'payment_method');
        // «ساعات الذروة» is a wall-clock question. In UTC it named 20:00 for a
        // rush the restaurants lived through at 22:00.
        $peakHours = (clone $orders)->selectRaw("HOUR({$localCreatedAt}) as hour, COUNT(*) as count")->groupBy('hour')->orderBy('hour')->pluck('count', 'hour');

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
            'payments_to_verify' => Payment::query()->where('status', 'under_review')->count(),
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
            // `is_online` is the WHERE above *and* the field the fleet board reads back:
            // `fleetState()` answers `offline` for a captain whose `is_online` is
            // `undefined`, so leaving the column out of the select made `ADM-56` count
            // every online captain as offline — three zeroed tiles, an empty list and a
            // map with no markers, while `ADM-19` beside it showed the same captain
            // «متصل ومتفرغ» because that screen selects the whole row.
            ->get(['id', 'user_id', 'current_lat', 'current_lng', 'location_updated_at', 'active_order_id', 'is_online', 'is_available', 'is_active'])
            // A captain with no fix has no marker. Serving the zeros put them in
            // the Atlantic off West Africa and stretched `ADM-56`'s camera across
            // an ocean to fit one of them in.
            ->each(function (Driver $driver): void {
                if ($driver->hasFix()) {
                    return;
                }

                $driver->current_lat = null;
                $driver->current_lng = null;
            });

        return ApiResponse::success($drivers);
    }
}
