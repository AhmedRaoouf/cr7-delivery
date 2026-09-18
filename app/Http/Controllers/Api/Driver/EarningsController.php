<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Api\Restaurant\SettlementController as RestaurantSettlementController;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Settlement;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use App\Support\DeliveryCommission;
use App\Support\DriverLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §5 — GET /driver/earnings, /settlements, /stats. */
class EarningsController extends Controller
{
    use FiltersLists;

    public function earnings(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        // Batch 68 — `filter[date_from]` / `filter[date_to]` beside the bare `from` / `to`
        // the app sends, all read on the Cairo clock (the bare ones were parsed as UTC, so
        // «اليوم» started at 02:00 in Gamasa), and a malformed one is a 422, not a 500.
        $params = $this->listParams($request);
        [$from, $to] = $params->dates();
        $from ??= $params->date('from') ?? BusinessTime::startOfDaysAgo(30);
        $to ??= $params->date('to', endOfDay: true) ?? BusinessTime::endOfToday();

        $transactions = $driver->transactions()->whereBetween('created_at', [$from, $to])->latest()->get();

        // `filter[type]` narrows the rows the screen lists; the sums above them stay the
        // window's own, or the balances would appear to move with a chip.
        $types = $params->enum('type', ['order_earning', 'bonus', 'deduction', 'cash_settlement', 'cash_collected', 'cash_handover', 'payout', 'place_advance']);
        $listed = $types === [] ? $transactions : $transactions->whereIn('type', $types)->values();

        return ApiResponse::success([
            ...DriverLedger::balances($driver),
            'total_earned' => (float) $transactions->where('type', 'order_earning')->sum('amount'),
            'orders_count' => $transactions->where('type', 'order_earning')->count(),
            // D-103 — the two other lines the balances are made of: what the captain took
            // at doors, and what he handed places at counters out of it.
            'cash_collected' => round((float) $transactions->where('type', 'cash_collected')->sum('amount'), 2),
            'paid_to_places' => round((float) $transactions->where('type', 'place_advance')->sum('amount'), 2),
            // What the captain handed the office inside the window, and what the office
            // handed them. Without these the balances look like they drift on their own.
            'cash_handed_over' => round(abs((float) $transactions->where('type', 'cash_handover')->sum('amount')), 2),
            'paid_out' => round(abs((float) $transactions->where('type', 'payout')->sum('amount')), 2),
            'commission_rate' => DeliveryCommission::rate(),
            'breakdown' => $listed->map(fn ($t) => [
                'id' => $t->id,
                'order_id' => $t->order_id,
                'type' => $t->type,
                // D-71 — the only question a captain actually has about a row:
                // `wallet` is their own money, `cash` is the platform's, in their pocket.
                'meter' => DriverLedger::meterOf($t->type),
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'note' => $t->note,
                'created_at' => $t->created_at,
            ]),
        ]);
    }

    /** DRV-26 — the earning line for one order, with the trip it was paid for. */
    public function earning(Request $request, Order $order): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        abort_unless($order->driver_id === $driver->id, 404);

        $transactions = $driver->transactions()->where('order_id', $order->id)->latest()->get();

        return ApiResponse::success([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'restaurant_name' => $order->restaurant_name_snapshot,
            'distance_km' => (float) $order->distance_km,
            'delivered_at' => $order->delivered_at,
            'driver_earning' => (float) $order->driver_earning,
            'payment_method' => $order->payment_method,
            // D-70 — the fee split three ways on one screen, so «ليه أخدت 21 من 25»
            // never has to be asked. The margin is the platform's share of the fee,
            // snapshotted at assignment.
            'delivery_fee' => (float) $order->delivery_fee_share,
            'platform_margin' => (float) $order->platform_delivery_margin,
            'commission_rate' => DeliveryCommission::rate(),
            // D-103 — on a cash trip the captain takes the whole door total and has
            // already handed the place its figure at the counter; what is left of the
            // one after the other and after his own share is the platform's.
            'cash_collected' => round((float) $transactions->where('type', 'cash_collected')->sum('amount'), 2),
            'paid_to_place' => round((float) $transactions->where('type', 'place_advance')->sum('amount'), 2),
            'free_delivery_reason' => $order->free_delivery_reason,
            'owed_to_platform' => round(
                (float) $transactions->where('type', 'cash_collected')->sum('amount')
                - (float) $transactions->where('type', 'place_advance')->sum('amount')
                - (float) $transactions->where('type', 'order_earning')->sum('amount'),
                2,
            ),
            'transactions' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'meter' => DriverLedger::meterOf($t->type),
                'amount' => (float) $t->amount,
                'note' => $t->note,
                'created_at' => $t->created_at,
            ]),
        ]);
    }

    /**
     * doc 62 §4.3 — «اللي عليا واللي ليا»: every row since the last settlement, grouped
     * the way the office settles it. The same document the office's settle dialog reads.
     */
    public function unsettled(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        return ApiResponse::success(DriverLedger::unsettled($driver));
    }

    public function settlements(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['period_end', 'created_at'], '-period_end');

        $query = Settlement::query()
            ->where('party_type', 'driver')
            ->where('party_id', $driver->id);

        $this->whereInList($query, 'status', $params->enum('status', ['draft', 'pending', 'paid']));
        RestaurantSettlementController::overlapping($query, $params->dates());

        $settlements = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($settlements, sort: $sort);
    }

    public function stats(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        $totalAttempts = $driver->driverAttempts()->whereIn('result', ['accepted', 'rejected', 'expired'])->count();
        $accepted = $driver->driverAttempts()->where('result', 'accepted')->count();

        return ApiResponse::success([
            'acceptance_rate' => $totalAttempts > 0 ? round($accepted / $totalAttempts * 100, 1) : null,
            'completed_orders' => $driver->completed_orders,
            'cancelled_orders' => $driver->cancelled_orders,
            'completion_rate' => ($driver->completed_orders + $driver->cancelled_orders) > 0
                ? round($driver->completed_orders / ($driver->completed_orders + $driver->cancelled_orders) * 100, 1)
                : null,
            'rating_avg' => (float) $driver->rating_avg,
            'rating_count' => $driver->rating_count,
        ]);
    }
}
