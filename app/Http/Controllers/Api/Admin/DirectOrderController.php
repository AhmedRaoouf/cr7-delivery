<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 / doc 22 §4.6 — cross-restaurant direct-order oversight. */
class DirectOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('origin', 'restaurant_direct')
            ->with('restaurant')
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->when($request->integer('filter.restaurant_id'), fn ($q, $id) => $q->where('restaurant_id', $id))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($orders->items(), meta: [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
        ]);
    }

    public function ratio(Restaurant $restaurant): JsonResponse
    {
        $windowDays = 30;
        $since = now()->subDays($windowDays);

        $total = $restaurant->orders()->where('created_at', '>=', $since)->count();
        $direct = $restaurant->orders()->where('origin', 'restaurant_direct')->where('created_at', '>=', $since)->count();

        $ratio = $total > 0 ? round($direct / $total, 4) : 0;
        $threshold = (float) (Setting::query()->where('key', 'direct_orders.ratio_alert_threshold')->value('value') ?? 0.4);

        return ApiResponse::success([
            'window_days' => $windowDays,
            'total_orders' => $total,
            'direct_orders' => $direct,
            'ratio' => $ratio,
            'alert' => $ratio >= $threshold,
        ]);
    }
}
