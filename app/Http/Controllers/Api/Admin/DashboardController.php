<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ErrandStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\PaymentProofResource;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** doc 08 §7 — dashboard & alerts. */
class DashboardController extends Controller
{
    /** Per kind. The panel is a wake-up call, not a work queue. */
    private const ALERT_LIMIT = 20;

    public function summary(Request $request): JsonResponse
    {
        // «اليوم» is a Cairo day, not a UTC one — see `BusinessTime`.
        $from = BusinessTime::fromInput($request->date('filter.date_from')) ?? BusinessTime::startOfToday();
        $to = BusinessTime::fromInput($request->date('filter.date_to'), endOfDay: true) ?? Carbon::now();

        $orders = AdminScope::applyToOrders(Order::query(), $request->user())->whereBetween('created_at', [$from, $to]);

        return ApiResponse::success([
            'orders_total' => (clone $orders)->count(),
            'orders_delivered' => (clone $orders)->where('status', 'delivered')->count(),
            'orders_cancelled' => (clone $orders)->where('status', 'cancelled')->count(),
            'gmv' => (float) (clone $orders)->where('status', 'delivered')->sum('subtotal'),
            'commission_total' => (float) (clone $orders)->where('status', 'delivered')->sum('commission_amount'),
            'platform_fees_total' => (float) (clone $orders)->where('status', 'delivered')->sum('platform_fee_share'),
            /*
             * 🔴 «دخل المنصة» left out the platform's actual income.
             *
             * D-70 has said since 2026-09-05 that the platform keeps 15 % of every
             * delivery fee, and `orders.platform_delivery_margin` has carried it per
             * order — but this figure was `commission + service fee`, neither of which
             * the operator charges any more (D-75, 2026-09-11: «عمولة المنصة كلها ‪15‬٪
             * على أي order من فلوس الديليفري، مش عايز حاجة غيرها»). Left alone, the
             * dashboard would have reported a platform that earns **nothing**.
             *
             * The two retired lines stay in the sum rather than being deleted: the
             * historical orders in this table were charged them, and a month that
             * straddles today has to add up.
             */
            'delivery_margin_total' => (float) (clone $orders)->where('status', 'delivered')->sum('platform_delivery_margin'),
            'late_cancellation_fees_total' => (float) (clone $orders)->sum('cancellation_fee'),
            'platform_revenue_total' => (float) (clone $orders)->where('status', 'delivered')->sum('platform_delivery_margin')
                + (float) (clone $orders)->where('status', 'delivered')->sum('commission_amount')
                + (float) (clone $orders)->where('status', 'delivered')->sum('platform_fee_share')
                + (float) (clone $orders)->sum('cancellation_fee'),
            'active_orders' => (clone $orders)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'online_drivers' => AdminScope::applyToDrivers(Driver::query(), $request->user())->where('is_online', true)->count(),
        ]);
    }

    public function charts(Request $request): JsonResponse
    {
        $from = BusinessTime::fromInput($request->date('filter.date_from')) ?? BusinessTime::startOfDaysAgo(7);
        $to = BusinessTime::fromInput($request->date('filter.date_to'), endOfDay: true) ?? Carbon::now();

        $orders = AdminScope::applyToOrders(Order::query(), $request->user())->whereBetween('created_at', [$from, $to]);

        $localCreatedAt = BusinessTime::sqlLocal('created_at');

        $sales = (clone $orders)->where('status', 'delivered')
            ->selectRaw("DATE({$localCreatedAt}) as date, SUM(subtotal) as gmv, COUNT(*) as orders_count")
            ->groupBy('date')->orderBy('date')->get();

        $statuses = (clone $orders)->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $paymentMethods = (clone $orders)->selectRaw('payment_method, COUNT(*) as count')->groupBy('payment_method')->pluck('count', 'payment_method');
        // «ساعات الذروة» is a wall-clock question. In UTC it named 20:00 for a
        // rush the restaurants lived through at 22:00.
        $peakHours = (clone $orders)->selectRaw("HOUR({$localCreatedAt}) as hour, COUNT(*) as count")->groupBy('hour')->orderBy('hour')->pluck('count', 'hour');

        return ApiResponse::success([
            'sales' => $sales,
            'statuses' => $statuses,
            'payment_methods' => $paymentMethods,
            'peak_hours' => $peakHours,
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        // The same numbers `openAlerts()` publishes, under `ADM-03`'s older names.
        // One source, so the sidebar badge and the dock can never disagree.
        //
        // 🔴 They did disagree. `alertCounts()` has computed six kinds since the
        // errand queue and the complaints dock were added, and this endpoint
        // published four of them — so the badge read «7» over a list of nine,
        // and the two queues that had no number were the two an operator is
        // least likely to be sitting on already.
        $counts = $this->alertCounts($request);

        return ApiResponse::success([
            'restaurant_timeouts' => $counts['restaurant_timeout'],
            'no_driver' => $counts['no_driver'],
            'delivery_issues' => $counts['delivery_issue'],
            'payments_to_verify' => $counts['payment_review'],
            'errands_unclaimed' => $counts['errand_unclaimed'],
            'complaints_open' => $counts['complaint_open'],
        ]);
    }

    /**
     * `GET /admin/dashboard/open-alerts` — the same four alerts as {@see self::alerts()},
     * but as the rows themselves rather than four numbers.
     *
     * Operator, 2026-09-05: «التنبيهات المفتوحة … لازم أقبلها بسرعة أو أرفضها بسرعة …
     * فما تنفعش تبقى مستخبية». A count tells an operator that something is wrong and
     * then makes them go and find it; a payment awaiting review is worth money and is
     * on a fifteen-minute deadline, and it needs to arrive with its receipt attached
     * and its two buttons on it.
     *
     * Ordered by urgency, then by age: money first, then an order nobody has taken,
     * then a restaurant that never answered, then a delivery in trouble. Capped, because
     * this is a wake-up call and not a work queue — the full lists live on their pages.
     */
    public function openAlerts(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user());

        $payments = Payment::query()
            ->where('status', 'under_review')
            ->with(['proofs' => fn ($q) => $q->latest(), 'orderGroup:id,group_number,user_id'])
            ->latest()
            ->limit(self::ALERT_LIMIT)
            ->get()
            ->map(fn (Payment $payment) => [
                'kind' => 'payment_review',
                'id' => $payment->id,
                'title' => 'تحويل بانتظار المراجعة',
                'reference' => $payment->orderGroup?->group_number,
                'order_group_id' => $payment->order_group_id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'deadline_at' => $payment->deadline_at,
                'created_at' => $payment->created_at,
                'href' => '/money/payments',
                // The receipt travels with the alert. Making the operator open a
                // second screen to see the image is the thing that made this slow.
                'receipts' => PaymentProofResource::forAdmin($payment->proofs),
            ]);

        $stranded = self::strandedQuery(clone $orders)
            ->with('restaurant:id,name_ar')
            ->oldest('dispatch_started_at')
            ->limit(self::ALERT_LIMIT)
            ->get()
            ->map(fn (Order $order) => $this->orderAlert($order, 'no_driver', 'طلب لم نجد له كابتن'));

        $timedOut = (clone $orders)
            ->where('status', 'restaurant_timeout')
            ->with('restaurant:id,name_ar')
            ->latest('updated_at')
            ->limit(self::ALERT_LIMIT)
            ->get()
            ->map(fn (Order $order) => $this->orderAlert($order, 'restaurant_timeout', 'المطعم لم يرد على الطلب'));

        $issues = (clone $orders)
            ->where('status', 'delivery_issue')
            ->with('restaurant:id,name_ar')
            ->latest('updated_at')
            ->limit(self::ALERT_LIMIT)
            ->get()
            ->map(fn (Order $order) => $this->orderAlert($order, 'delivery_issue', 'مشكلة في التوصيل'));

        // doc 40 §3 — an errand nobody took. It joins the dock rather than getting its
        // own surface because it is the same question the other four ask: something is
        // waiting on a person and nobody has picked it up. `ErrandDispatcher` pushes once
        // at five minutes; this is what keeps it in front of the operator afterwards.
        $errands = AdminScope::applyToOrders(ErrandRequest::query(), $request->user())
            ->where('status', ErrandStatus::Open->value)
            ->whereNull('driver_id')
            ->oldest()
            ->limit(self::ALERT_LIMIT)
            ->get()
            ->map(fn (ErrandRequest $errand) => [
                'kind' => 'errand_unclaimed',
                'id' => $errand->id,
                'title' => 'مشوار بانتظار كابتن',
                'reference' => $errand->reference,
                'order_group_id' => null,
                'amount' => (float) $errand->offered_fee,
                'restaurant' => $errand->pickup_address_text,
                'created_at' => $errand->created_at,
                'href' => '/errands/'.$errand->id,
                'receipts' => [],
            ]);

        // D-53 — a complaint is «something is waiting on a person and nobody has picked
        // it up», which is exactly what this dock is for. It was the one open queue that
        // had no way of reaching an operator who was not already on `ADM-41`.
        $complaints = Complaint::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->with('user:id,name')
            ->oldest()
            ->limit(self::ALERT_LIMIT)
            ->get()
            ->map(fn (Complaint $complaint) => [
                'kind' => 'complaint_open',
                'id' => $complaint->id,
                'title' => $complaint->priority?->value === 'high'
                    ? 'شكوى عاجلة بانتظار الرد'
                    : 'شكوى بانتظار الرد',
                'reference' => $complaint->ticket_number,
                'order_group_id' => $complaint->order_group_id,
                'amount' => 0.0,
                'restaurant' => $complaint->user?->name,
                'created_at' => $complaint->created_at,
                'href' => '/complaints/'.$complaint->id,
                'receipts' => [],
            ]);

        $items = $payments->concat($stranded)->concat($timedOut)->concat($issues)
            ->concat($errands)->concat($complaints);

        /*
         * The counts are the **true** totals, not `$items->count()`.
         *
         * `items` is capped at `ALERT_LIMIT` per kind because the dock is a wake-up
         * call, not a work queue. Deriving the badge from the capped list made the
         * sidebar and the dock read «20» on a night with 137 stranded orders — a
         * number that stops moving is read as «handled», which is the opposite of
         * what an alert is for. `total > items->count()` is how the client knows to
         * offer the full list.
         */
        $counts = $this->alertCounts($request);
        $total = array_sum($counts);

        return ApiResponse::success([
            'total' => $total,
            'shown' => $items->count(),
            'counts' => $counts,
            'items' => $items->values(),
        ]);
    }

    /**
     * The four open-alert counts, scoped to the operator (D-50).
     *
     * @return array{payment_review:int, no_driver:int, restaurant_timeout:int, delivery_issue:int, errand_unclaimed:int, complaint_open:int}
     */
    private function alertCounts(Request $request): array
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user());

        return [
            'payment_review' => Payment::query()->where('status', 'under_review')->count(),
            'no_driver' => self::strandedQuery(clone $orders)->count(),
            'restaurant_timeout' => (clone $orders)->where('status', 'restaurant_timeout')->count(),
            'delivery_issue' => (clone $orders)->where('status', 'delivery_issue')->count(),
            'errand_unclaimed' => AdminScope::applyToOrders(ErrandRequest::query(), $request->user())
                ->where('status', ErrandStatus::Open->value)
                ->whereNull('driver_id')
                ->count(),
            // Not scoped: `complaints` carries neither `city_id` nor `service_zone_id`,
            // so `AdminScope::applyToOrders` has nothing to filter on. Scoping it means
            // a column and a backfill, and a count an out-of-scope operator can see is
            // a smaller problem than a complaint nobody sees at all.
            'complaint_open' => Complaint::query()->whereIn('status', ['open', 'in_progress'])->count(),
        ];
    }

    /**
     * «في طلب مش لاقيين له delivery» — the operator's own words, 2026-09-11, about the
     * one thing the dock has to say and could not.
     *
     * It used to be `status = waiting_for_driver`, which is «the last pass found nobody
     * *new*». An order whose three offers are open and unanswered never reaches that
     * status at all — it stays `searching_driver` for as long as nobody answers — so the
     * orders most in need of an operator were the ones the dock could not see. And an
     * order left on `ready` by an unassign-without-requeue was invisible to both.
     *
     * Now: no captain, still cooking-done or searching, and the search started at least
     * `dispatch.escalate_after_seconds` (‪120‬) ago — the same threshold that pushes, so
     * the dock and the push can never disagree about what counts as stranded.
     */
    private static function strandedQuery(Builder $orders): Builder
    {
        $seconds = (int) (Setting::query()->where('key', 'dispatch.escalate_after_seconds')->value('value') ?? 120);

        return $orders
            ->whereIn('status', ['ready', 'searching_driver', 'waiting_for_driver'])
            ->whereNull('driver_id')
            ->whereNotNull('dispatch_started_at')
            ->where('dispatch_started_at', '<=', now()->subSeconds(max(30, $seconds)));
    }

    private function orderAlert(Order $order, string $kind, string $title): array
    {
        return [
            'kind' => $kind,
            'id' => $order->id,
            'title' => $title,
            'reference' => $order->order_number,
            'order_group_id' => $order->order_group_id,
            'amount' => (float) $order->total,
            'restaurant' => $order->restaurant?->name_ar,
            // Since when, not «last touched». `updated_at` is bumped by every widening
            // pass, so a stranded order looked seconds old for as long as it was
            // stranded — the one figure on the card that has to be true.
            'created_at' => $kind === 'no_driver'
                ? ($order->dispatch_started_at ?? $order->ready_at ?? $order->updated_at)
                : $order->updated_at,
            'href' => '/orders/'.$order->id,
            'receipts' => [],
        ];
    }

    public function liveOrders(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user())
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->with(['restaurant:id,name_ar,latitude,longitude', 'driver:id,current_lat,current_lng'])
            ->get(['id', 'order_number', 'status', 'restaurant_id', 'driver_id', 'delivery_latitude', 'delivery_longitude']);

        return ApiResponse::success($orders);
    }

    public function liveDrivers(Request $request): JsonResponse
    {
        $drivers = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->where('is_online', true)
            ->with('user:id,name')
            // `is_online` is the WHERE above *and* the field the fleet board reads back:
            // `fleetState()` answers `offline` for a captain whose `is_online` is
            // `undefined`, so leaving the column out of the select made `ADM-56` count
            // every online captain as offline — three zeroed tiles, an empty list and a
            // map with no markers, while `ADM-19` beside it showed the same captain
            // «متصل ومتفرغ» because that screen selects the whole row.
            ->get(['id', 'user_id', 'current_lat', 'current_lng', 'location_updated_at', 'active_order_id', 'is_online', 'is_available', 'is_active'])
            // A captain with no fix has no marker. Serving the zeros put them in
            // the Atlantic off West Africa and stretched `ADM-56`'s camera across
            // an ocean to fit one of them in.
            ->each(function (Driver $driver): void {
                if ($driver->hasFix()) {
                    return;
                }

                $driver->current_lat = null;
                $driver->current_lng = null;
            });

        return ApiResponse::success($drivers);
    }
}
