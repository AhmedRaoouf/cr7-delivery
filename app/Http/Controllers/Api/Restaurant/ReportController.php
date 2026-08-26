<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** doc 08 §6 — GET /restaurant/reports/summary | /sales | /top-products. */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        [$from, $to] = $this->range($request);

        $orders = $restaurant->orders()->whereBetween('created_at', [$from, $to]);

        return ApiResponse::success([
            'orders_count' => (clone $orders)->count(),
            'delivered_count' => (clone $orders)->where('status', 'delivered')->count(),
            'cancelled_count' => (clone $orders)->where('status', 'cancelled')->count(),
            'gross_sales' => (float) (clone $orders)->where('status', 'delivered')->sum('subtotal'),
            'commission_total' => (float) (clone $orders)->where('status', 'delivered')->sum('commission_amount'),
            'rating_avg' => (float) $restaurant->rating_avg,
        ]);
    }

    public function sales(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        [$from, $to] = $this->range($request);

        $series = $restaurant->orders()
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders_count, SUM(subtotal) as gross_sales')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return ApiResponse::success($series);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $products = $restaurant->products()->orderByDesc('sold_count')->limit(10)->get(['id', 'name_ar', 'name_en', 'sold_count', 'price']);

        return ApiResponse::success($products);
    }

    private function range(Request $request): array
    {
        return [
            $request->date('from')?->startOfDay() ?? Carbon::now()->subDays(30)->startOfDay(),
            $request->date('to')?->endOfDay() ?? Carbon::now()->endOfDay(),
        ];
    }
}
