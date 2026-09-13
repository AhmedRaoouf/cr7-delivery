<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 62 §4.4 — «كل واحد بيبقى محسوب له قدامه» for the places.
 *
 * Under D-103 the platform owes a place its food money on the orders the customer paid
 * **online** — the cash ones the captain paid at the counter. Before a statement is
 * generated, nothing anywhere said how much that was: the office had to generate a
 * statement to find out whether one was worth generating. This is the fleet view's twin
 * for places: what each is owed and has not yet been put on a statement.
 */
class RestaurantFinanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurants = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->select(['id', 'name_ar', 'name_en', 'phone', 'city_id'])
            ->orderBy('name_ar')
            ->get();

        $unclaimed = Order::query()
            ->where('status', 'delivered')
            ->whereNull('restaurant_settlement_id')
            ->where('origin', '!=', 'restaurant_direct')
            ->whereIn('restaurant_id', $restaurants->pluck('id'))
            ->get(['id', 'restaurant_id', 'payment_method', 'subtotal', 'commission_amount', 'delivered_at'])
            ->groupBy('restaurant_id');

        $lastStatements = Settlement::query()
            ->where('party_type', 'restaurant')
            ->whereIn('party_id', $restaurants->pluck('id'))
            ->selectRaw('party_id, MAX(period_end) as last_period_end')
            ->groupBy('party_id')
            ->pluck('last_period_end', 'party_id');

        $rows = $restaurants->map(function (Restaurant $restaurant) use ($unclaimed, $lastStatements) {
            $orders = $unclaimed->get($restaurant->id, collect());
            $online = $orders->reject(fn (Order $o) => $o->payment_method?->value === 'cash');
            $cash = $orders->filter(fn (Order $o) => $o->payment_method?->value === 'cash');

            return [
                'restaurant_id' => $restaurant->id,
                'name' => $restaurant->name_ar,
                'phone' => $restaurant->phone,
                'owed_online' => round((float) $online->sum(fn (Order $o) => $o->restaurant_amount), 2),
                'online_orders' => $online->count(),
                'paid_at_pickup' => round((float) $cash->sum(fn (Order $o) => $o->restaurant_amount), 2),
                'cash_orders' => $cash->count(),
                'oldest_unclaimed_at' => $orders->min('delivered_at')?->toIso8601String(),
                'last_statement_end' => $lastStatements->get($restaurant->id),
            ];
        })->sortByDesc('owed_online')->values();

        return ApiResponse::success($rows, meta: [
            'totals' => [
                'owed_online' => round((float) $rows->sum('owed_online'), 2),
                'paid_at_pickup' => round((float) $rows->sum('paid_at_pickup'), 2),
            ],
        ]);
    }
}
