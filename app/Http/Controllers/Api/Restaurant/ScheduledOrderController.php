<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\ScheduledOrderResource;
use App\Models\ScheduledOrder;
use App\Support\ApiResponse;
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
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant()->value('id');

        $rows = ScheduledOrder::query()
            ->whereHas('restaurants', fn ($q) => $q->where('restaurants.id', $restaurantId))
            ->where('status', ScheduledOrder::STATUS_SCHEDULED)
            ->with('customer')
            ->orderBy('scheduled_for')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(ScheduledOrderResource::collection($rows->getCollection()), meta: [
            'current_page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'last_page' => $rows->lastPage(),
        ]);
    }
}
