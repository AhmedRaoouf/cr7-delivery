<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\ScheduledOrderResource;
use App\Models\ScheduledOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «طلبات مجدولة» on the restaurant board.
 *
 * Read-only, on purpose. A scheduled basket is not an order yet — there is nothing to
 * accept, nothing to reject and no prep clock to start. What the kitchen needs from it
 * is the one thing this gives them: what is coming, and when, so they can buy for it.
 */
class ScheduledOrderController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant()->value('id');
        $params = $this->listParams($request);
        $sort = $params->sort(['scheduled_for'], 'scheduled_for');

        $query = ScheduledOrder::query()
            ->whereHas('restaurants', fn ($q) => $q->where('restaurants.id', $restaurantId))
            ->where('status', ScheduledOrder::STATUS_SCHEDULED)
            ->with('customer');

        $this->whereBetweenDates($query, 'scheduled_orders.scheduled_for', $params->dates());

        $rows = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($rows, ScheduledOrderResource::collection($rows->getCollection()), sort: $sort);
    }
}
