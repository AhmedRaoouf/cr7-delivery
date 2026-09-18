<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['owed_online', 'name', 'paid_at_pickup', 'oldest_unclaimed_at'], '-owed_online');

        $query = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->select(['id', 'name_ar', 'name_en', 'phone', 'city_id']);

        $this->whereInList($query, 'restaurants.city_id', $params->ids('city_id'));

        ListSearch::apply($query, $params->search(), [
            'names' => ['name_ar', 'name_en'],
            'phones' => ['phone'],
        ]);

        $restaurants = $query->orderBy('name_ar')->get();

        // Summed by the database, per place and per side of the counter — this screen used
        // to load every unclaimed delivered order into memory to add them up in PHP. The
        // amount is `Order::restaurant_amount`: the food, less the commission.
        $unclaimed = Order::query()
            ->where('status', 'delivered')
            ->whereNull('restaurant_settlement_id')
            ->where('origin', '!=', 'restaurant_direct')
            ->whereIn('restaurant_id', $restaurants->pluck('id'))
            ->selectRaw("restaurant_id, COALESCE(payment_method = 'cash', 0) as is_cash, COUNT(*) as orders, SUM(subtotal - commission_amount) as amount, MIN(delivered_at) as oldest")
            ->groupBy('restaurant_id', 'is_cash')
            ->toBase()
            ->get()
            ->groupBy('restaurant_id');

        $lastStatements = Settlement::query()
            ->where('party_type', 'restaurant')
            ->whereIn('party_id', $restaurants->pluck('id'))
            ->selectRaw('party_id, MAX(period_end) as last_period_end')
            ->groupBy('party_id')
            ->pluck('last_period_end', 'party_id');

        $rows = $restaurants->map(function (Restaurant $restaurant) use ($unclaimed, $lastStatements) {
            $sides = $unclaimed->get($restaurant->id, collect());
            $online = $sides->first(fn ($side) => ! (bool) $side->is_cash);
            $cash = $sides->first(fn ($side) => (bool) $side->is_cash);
            $oldest = $sides->pluck('oldest')->filter()->min();

            return [
                'restaurant_id' => $restaurant->id,
                'name' => $restaurant->name_ar,
                'phone' => $restaurant->phone,
                'owed_online' => round((float) ($online->amount ?? 0), 2),
                'online_orders' => (int) ($online->orders ?? 0),
                'paid_at_pickup' => round((float) ($cash->amount ?? 0), 2),
                'cash_orders' => (int) ($cash->orders ?? 0),
                'oldest_unclaimed_at' => $oldest === null ? null : Carbon::parse($oldest, 'UTC')->toIso8601String(),
                'last_statement_end' => $lastStatements->get($restaurant->id),
            ];
        });

        if (($owed = $params->bool('owed')) !== null) {
            $rows = $rows->filter(fn (array $row) => ($row['owed_online'] > 0) === $owed);
        }

        $key = $sort->field === 'name' ? 'name' : $sort->field;
        $rows = ($sort->descending ? $rows->sortByDesc($key) : $rows->sortBy($key))->values();

        return ApiResponse::success($rows, meta: [
            'sort' => (string) $sort,
            'totals' => [
                'owed_online' => round((float) $rows->sum('owed_online'), 2),
                'paid_at_pickup' => round((float) $rows->sum('paid_at_pickup'), 2),
            ],
        ]);
    }
}
