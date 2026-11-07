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
use App\Support\PlaceAccount;
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
        $sort = $params->sort(['owed_online', 'name', 'paid_at_pickup', 'oldest_unclaimed_at', 'balance', 'owed_by_place'], '-owed_online');

        $query = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->select(['id', 'name_ar', 'name_en', 'phone', 'city_id']);

        $this->whereInList($query, 'restaurants.city_id', $params->ids('city_id'));

        ListSearch::apply($query, $params->search(), [
            'names' => ['name_ar', 'name_en'],
            'phones' => ['phone'],
        ]);

        $restaurants = $query->orderBy('name_ar')->get();

        // doc 103 — every figure from `PlaceAccount`, the same sum the statement makes:
        // «مستحق» here used to skip refunds and disagree with the statement it produced.
        $unclaimed = Order::query()
            ->where('status', 'delivered')
            ->whereNull('restaurant_settlement_id')
            ->whereIn('restaurant_id', $restaurants->pluck('id'))
            ->get()
            ->groupBy('restaurant_id');

        $lastStatements = Settlement::query()
            ->where('party_type', 'restaurant')
            ->whereIn('party_id', $restaurants->pluck('id'))
            ->selectRaw('party_id, MAX(period_end) as last_period_end')
            ->groupBy('party_id')
            ->pluck('last_period_end', 'party_id');

        $rows = $restaurants->map(function (Restaurant $restaurant) use ($unclaimed, $lastStatements) {
            $orders = $unclaimed->get($restaurant->id, collect());
            $account = PlaceAccount::of($orders);
            $oldest = $orders->reject(fn (Order $order) => $order->isDirect())->min('delivered_at');

            return [
                'restaurant_id' => $restaurant->id,
                'name' => $restaurant->name_ar,
                'phone' => $restaurant->phone,
                'commission_rate' => round((float) $restaurant->commission_rate, 2),
                'owed_online' => $account['online_owed_to_place'],
                'online_orders' => $account['online_orders_count'],
                'paid_at_pickup' => $account['paid_at_pickup_total'],
                'cash_orders' => $account['cash_orders_count'],
                // doc 103 — what the place owes back on its cash orders (commission, its discount).
                'owed_by_place' => $account['cash_owed_by_place'],
                'commission_total' => $account['commission_total'],
                // The statement this would make now: positive, the platform pays the place.
                'balance' => $account['balance'],
                'direction' => $account['direction'],
                'oldest_unclaimed_at' => $oldest === null ? null : Carbon::parse($oldest)->utc()->toIso8601String(),
                'last_statement_end' => $lastStatements->get($restaurant->id),
            ];
        });

        if (($owed = $params->bool('owed')) !== null) {
            // doc 103 — «has money to settle» runs both ways now.
            $rows = $rows->filter(fn (array $row) => ($row['direction'] !== 'settled') === $owed);
        }

        $key = $sort->field === 'name' ? 'name' : $sort->field;
        $rows = ($sort->descending ? $rows->sortByDesc($key) : $rows->sortBy($key))->values();

        return ApiResponse::success($rows, meta: [
            'sort' => (string) $sort,
            'totals' => [
                'owed_online' => round((float) $rows->sum('owed_online'), 2),
                'paid_at_pickup' => round((float) $rows->sum('paid_at_pickup'), 2),
                'owed_by_place' => round((float) $rows->sum('owed_by_place'), 2),
                'commission_total' => round((float) $rows->sum('commission_total'), 2),
            ],
        ]);
    }
}
