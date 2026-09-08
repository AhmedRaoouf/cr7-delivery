<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use App\Support\ReportExporter;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §6 — GET /restaurant/reports/summary | /sales | /top-products. */
class ReportController extends Controller
{
    /** A mis-set date must not stream a million rows through a shared-hosting worker. */
    private const MAX_EXPORT_ROWS = 20000;

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

    /**
     * `RES-21` — «الصنف · الكمية · الإيراد», over a period.
     *
     * Counted from `order_items` on delivered orders inside the range, not from
     * `products.sold_count`. Two reasons, and the second is what made the screen wrong:
     * `sold_count` is a **lifetime** counter, so no date filter can narrow it — and it is
     * not `quantity` or `revenue`, which are the two columns `RES-21` renders. The screen
     * asked for fields this endpoint did not return, so every row read «0» and «0.00 ج.م»
     * with the item name beside it.
     *
     * `product_name_ar_snapshot` rather than a join to `products`: a dish renamed or
     * deleted since the order still has to appear in the month it sold in.
     */
    public function topProducts(Request $request): JsonResponse
    {
        return ApiResponse::success($this->topProductRows($request, 10)->all());
    }

    /**
     * «تصدير إكسل» on `RES-20` — the sales series, with the two charts beside it.
     *
     * The second worksheet, «بيانات الرسم البياني», carries exactly what «مبيعات بالأيام»
     * and «الطلبات عبر الفترة» plot, on one date axis, so the owner can insert a live
     * chart over a clean range. openspout cannot write a chart part itself — the whole
     * reasoning is on [ReportExporter].
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
                ['الفترة', BusinessTime::localDate($from).' — '.BusinessTime::localDate($to)],
                ['إجمالي المبيعات', round((float) $series->sum('gross_sales'), 2)],
                ['إجمالي العمولة', round((float) $series->sum('commission'), 2)],
            ],
            [
                'title' => 'المبيعات وعدد الطلبات عبر الفترة',
                'headers' => ['التاريخ', 'إجمالي المبيعات', 'عدد الطلبات'],
                'rows' => $series->map(fn ($row) => [
                    (string) $row->date,
                    (float) $row->gross_sales,
                    (int) $row->orders_count,
                ])->all(),
            ],
        );
    }

    /**
     * «تصدير إكسل» on `RES-21` — الأصناف الأكثر مبيعًا.
     *
     * A screen with rows and columns and no download was the one report in either
     * dashboard the operator could not take away. No chart is drawn on it, so no chart
     * sheet: an empty «بيانات الرسم البياني» tab would only invite the question.
     *
     * Unlike the JSON endpoint beside it this is not capped at ten — a file the owner
     * opens in Excel is where «and the rest of them» belongs.
     */
    public function topProductsExport(Request $request): StreamedResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        [$from, $to] = $this->range($request);

        $rows = $this->topProductRows($request, self::MAX_EXPORT_ROWS);

        return ReportExporter::stream(
            'top-products-'.BusinessTime::now()->format('Ymd-Hi').'.xlsx',
            'الأصناف الأكثر مبيعًا — '.$restaurant->name_ar,
            ['الصنف', 'الكمية المباعة', 'الإيراد'],
            (function () use ($rows): Generator {
                foreach ($rows as $row) {
                    yield [
                        (string) $row->name_ar,
                        (int) $row->quantity,
                        round((float) $row->revenue, 2),
                    ];
                }
            })(),
            [
                ['الفترة', BusinessTime::localDate($from).' — '.BusinessTime::localDate($to)],
                ['إجمالي الإيراد', round((float) $rows->sum('revenue'), 2)],
            ],
        );
    }

    /**
     * The rows behind `RES-21` and behind its download, from one query.
     *
     * @return Collection<int, object>
     */
    private function topProductRows(Request $request, int $limit): Collection
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        [$from, $to] = $this->range($request);

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurant->id)
            ->where('orders.status', 'delivered')
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('order_items.product_id as product_id')
            ->selectRaw('MAX(order_items.product_name_ar_snapshot) as name_ar')
            ->selectRaw('MAX(order_items.product_name_en_snapshot) as name_en')
            ->selectRaw('SUM(order_items.quantity) as quantity')
            ->selectRaw('SUM(order_items.line_total) as revenue')
            ->groupBy('order_items.product_id')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();
    }

    /**
     * Both spellings of the period.
     *
     * Every caller — the dashboard's four queries and both export links — sends
     * `filter[date_from]`, which is the shape doc 08 §2 gives every list endpoint and the
     * one the admin reports already read. This controller read a bare `from`, so the date
     * range on `RES-20` was accepted by the form, carried in the URL, printed in the file
     * header, and **applied to nothing**: every figure was the default thirty days. The
     * bare keys stay as a fallback rather than being swapped, because they are what the
     * endpoint has answered to since it shipped.
     */
    private function range(Request $request): array
    {
        $from = $request->date('filter.date_from') ?? $request->date('from');
        $to = $request->date('filter.date_to') ?? $request->date('to');

        return [
            BusinessTime::fromInput($from) ?? BusinessTime::startOfDaysAgo(30),
            BusinessTime::fromInput($to, endOfDay: true) ?? BusinessTime::endOfToday(),
        ];
    }
}
