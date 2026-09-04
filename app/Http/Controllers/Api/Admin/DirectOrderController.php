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
    use \App\Http\Controllers\Concerns\FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('origin', 'restaurant_direct')
            ->with('restaurant')
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->when($this->filterInt($request, 'restaurant_id'), fn ($q, $id) => $q->where('restaurant_id', $id))
            // doc 08 §7 makes `filter[city_id]` global to every admin list; this one never
            // read it, so `ADM-46`'s city picker did nothing.
            ->when($this->filterInt($request, 'city_id'), fn ($q, $id) => $q->where('city_id', $id))
            ->when($this->filterString($request, 'search') !== '', function ($q) use ($request) {
                $term = '%'.$this->filterString($request, 'search').'%';
                // A direct order has no app customer, so the phone the operator has is the
                // one the restaurant typed onto the order itself.
                $q->where(fn ($sq) => $sq
                    ->where('order_number', 'like', $term)
                    ->orWhere('guest_customer_name', 'like', $term)
                    ->orWhere('guest_customer_phone', 'like', $term));
            })
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
