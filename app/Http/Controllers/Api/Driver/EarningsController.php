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
