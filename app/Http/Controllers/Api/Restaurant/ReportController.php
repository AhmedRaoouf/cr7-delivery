<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use App\Support\Lists\ListParams;
use App\Support\PlaceAccount;
use App\Support\ReportExporter;
use App\Support\SettlementCycle;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §6 — GET /restaurant/reports/summary | /sales | /top-products. */
class ReportController extends Controller
{
    /** A mis-set date must not stream a million rows through a shared-hosting worker. */
    private const MAX_EXPORT_ROWS = 20000;

    /**
     * `RES-20` — the place's numbers over a period, and its account as it stands now.
     *
     * doc 103 — the screen asked for `gross`, `refunds`, `net`, `net_payable`,
     * `avg_order_value`, `avg_prep_minutes` and `cancellation_rate`, and this answered
     * none of them, so seven of its eight tiles read 0. Every figure now comes from
     * `PlaceAccount`, the same sum the statement makes. The old keys stay for old apps.
     *
     * The period is by when the order was placed (as the order counts always were); the
     * money is from those orders that were delivered. `balance` is not the period: it is
     * every delivered order no statement has claimed yet — «ليك / عليك» right now.
     */
    public function summary(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        [$from, $to] = $this->range($request);

        $orders = $restaurant->orders()->whereBetween('created_at', [$from, $to]);
        $delivered = (clone $orders)->where('status', 'delivered')->get();
        $account = PlaceAccount::of($delivered);

        $ordersCount = (clone $orders)->count();
        $cancelledCount = (clone $orders)->where('status', 'cancelled')->count();
        $marketplaceDelivered = max(1, $account['marketplace_orders_count']);

        // From «بدأ التحضير» (or the accept) to «جاهز», on the orders that reached it.
        $prep = (clone $orders)->whereNotNull('ready_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, COALESCE(preparing_at, accepted_at), ready_at)) / 60 as minutes')
            ->whereNotNull(DB::raw('COALESCE(preparing_at, accepted_at)'))
            ->value('minutes');

        $open = PlaceAccount::of($restaurant->orders()
            ->where('status', 'delivered')
            ->whereNull('restaurant_settlement_id')
            ->get());

        return ApiResponse::success([
            'orders_count' => $ordersCount,
            'delivered_count' => $delivered->count(),
            'cancelled_count' => $cancelledCount,
            'cancellation_rate' => $ordersCount > 0 ? round($cancelledCount / $ordersCount, 4) : 0.0,
            'gross' => $account['food_total'],
            'commission' => $account['commission_total'],
            'commission_rate' => round((float) $restaurant->commission_rate, 2),
            'place_discount' => $account['place_discount_total'],
            'refunds' => $account['refunds_total'],
            'net' => $account['earned_total'],
            // What this period adds to the account: positive, the platform owes the place.
            'net_payable' => $account['balance'],
            'net_payable_direction' => $account['direction'],
            'avg_order_value' => $account['marketplace_orders_count'] > 0
                ? round($account['food_total'] / $marketplaceDelivered, 2)
                : 0.0,
            'avg_prep_minutes' => $prep === null ? null : round((float) $prep, 1),
            'cash_orders_count' => $account['cash_orders_count'],
            'cash_food_total' => $account['cash_food_total'],
            'cash_owed_by_place' => $account['cash_owed_by_place'],
            'online_orders_count' => $account['online_orders_count'],
            'online_food_total' => $account['online_food_total'],
            'online_owed_to_place' => $account['online_owed_to_place'],
            // «ليك / عليك» now, over every delivered order no statement has claimed.
            'balance' => $open['balance'],
            'balance_direction' => $open['direction'],
            'balance_orders_count' => $open['orders_count'],
            // doc 104 — the place's statement cycle, null when it is on no commission.
            'statement_cycle' => SettlementCycle::of($restaurant)?->toArray(),
            // The keys this answered before, for clients that still read them.
            'gross_sales' => $account['food_total'],
            'commission_total' => $account['commission_total'],
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
            ->get()
            // `RES-20`'s chart reads `gross`; every bar was 0 (doc 103).
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'orders_count' => (int) $row->orders_count,
                'gross' => round((float) $row->gross_sales, 2),
                'gross_sales' => round((float) $row->gross_sales, 2),
            ]);

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
        // Batch 68 — `ListParams`: a malformed date is a 422, not a 500.
        $params = ListParams::of($request);
        [$from, $to] = $params->dates();
        [$bareFrom, $bareTo] = $params->dates('from', 'to');

        return [
            $from ?? $bareFrom ?? BusinessTime::startOfDaysAgo(30),
            $to ?? $bareTo ?? BusinessTime::endOfToday(),
        ];
    }
}
