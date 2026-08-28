<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Settlement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** doc 08 §5 — GET /driver/earnings, /settlements, /stats. */
class EarningsController extends Controller
{
    public function earnings(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $from = $request->date('from') ?? Carbon::now()->subDays(30)->startOfDay();
        $to = $request->date('to') ?? Carbon::now()->endOfDay();

        $transactions = $driver->transactions()->whereBetween('created_at', [$from, $to])->latest()->get();

        return ApiResponse::success([
            'wallet_balance' => (float) $driver->wallet_balance,
            'cash_in_hand' => (float) $driver->cash_in_hand,
            'total_earned' => (float) $transactions->where('type', 'order_earning')->sum('amount'),
            'orders_count' => $transactions->where('type', 'order_earning')->count(),
            'breakdown' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'order_id' => $t->order_id,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'note' => $t->note,
                'created_at' => $t->created_at,
            ]),
        ]);
    }

    /** DRV-26 — the earning line for one order, with the trip it was paid for. */
    public function earning(Request $request, \App\Models\Order $order): JsonResponse
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
            // On a cash trip the captain holds the whole customer total, not just the
            // delivery fee — that is what the settlement nets off.
            'cash_collected' => $order->payment_method->value === 'cash' ? (float) $order->total : 0.0,
            'transactions' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'note' => $t->note,
                'created_at' => $t->created_at,
            ]),
        ]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        $settlements = Settlement::query()
            ->where('party_type', 'driver')
            ->where('party_id', $driver->id)
            ->latest('period_end')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($settlements->items(), meta: [
            'current_page' => $settlements->currentPage(),
            'per_page' => $settlements->perPage(),
            'total' => $settlements->total(),
            'last_page' => $settlements->lastPage(),
        ]);
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
