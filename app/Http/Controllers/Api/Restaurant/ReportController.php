<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use App\Support\ReportExporter;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->selectRaw('DATE('.BusinessTime::sqlLocal('created_at').') as date, COUNT(*) as orders_count, SUM(subtotal) as gross_sales')
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

    /**
     * «تصدير Excel» — the sales series and the top sellers, in one workbook.
     *
     * One file rather than two buttons: a restaurant asking «how did last month go»
     * wants both halves, and the second sheet would otherwise be a second download the
     * owner has to remember to take.
     */
    public function export(Request $request): StreamedResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        [$from, $to] = $this->range($request);

        $series = $restaurant->orders()
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE('.BusinessTime::sqlLocal('created_at').') as date, COUNT(*) as orders_count, SUM(subtotal) as gross_sales, SUM(commission_amount) as commission')
            ->groupBy('date')->orderBy('date')->get();

        return ReportExporter::stream(
            'restaurant-sales-'.BusinessTime::now()->format('Ymd-Hi').'.xlsx',
            'تقرير مبيعات '.$restaurant->name_ar,
            ['التاريخ', 'عدد الطلبات', 'إجمالي المبيعات', 'العمولة', 'الصافي'],
            (function () use ($series): Generator {
                foreach ($series as $row) {
                    yield [
                        (string) $row->date,
                        (int) $row->orders_count,
                        (float) $row->gross_sales,
                        (float) $row->commission,
                        round((float) $row->gross_sales - (float) $row->commission, 2),
                    ];
                }
            })(),
            [
                ['الفترة', $from->format('Y-m-d').' — '.$to->format('Y-m-d')],
                ['إجمالي المبيعات', round((float) $series->sum('gross_sales'), 2)],
                ['إجمالي العمولة', round((float) $series->sum('commission'), 2)],
            ],
        );
    }

    private function range(Request $request): array
    {
        return [
            BusinessTime::fromInput($request->date('from')) ?? BusinessTime::startOfDaysAgo(30),
            BusinessTime::fromInput($request->date('to'), endOfDay: true) ?? BusinessTime::endOfToday(),
        ];
    }
}
