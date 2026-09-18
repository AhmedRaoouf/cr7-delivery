<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 / doc 22 §4.6 — cross-restaurant direct-order oversight. */
class DirectOrderController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'id', 'status'], '-created_at');
        $statuses = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        $query = AdminScope::applyToOrders(Order::query(), $request->user())
            ->where('origin', 'restaurant_direct')
            ->with('restaurant');

        $this->whereInList($query, 'status', $params->enum('status', $statuses));
        $this->whereInList($query, 'restaurant_id', $params->ids('restaurant_id'));
        $this->whereInList($query, 'driver_id', $params->ids('driver_id'));
        // doc 08 §7 makes `filter[city_id]` global to every admin list; this one never
        // read it, so `ADM-46`'s city picker did nothing.
        $this->whereInList($query, 'city_id', $params->ids('city_id'));
        $this->whereInList($query, 'service_zone_id', $params->ids('zone_id'));
        $this->whereBetweenDates($query, 'created_at', $params->dates());

        // A direct order has no app customer, so the phone the operator has is the one the
        // restaurant typed onto the order itself.
        ListSearch::apply($query, $params->search(), [
            'refs' => ['order_number'],
            'names' => ['guest_customer_name'],
            'phones' => ['guest_customer_phone', 'guest_customer_phone_alt'],
        ]);

        $orders = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($orders, sort: $sort);
    }

    public function ratio(Request $request, Restaurant $restaurant): JsonResponse
    {
        // Batch 67 — the ratio is a fact about one restaurant; it follows that restaurant's scope.
        AdminScope::abortUnlessVisible(AdminScope::applyToRestaurants(Restaurant::query(), $request->user()), $restaurant);

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
