<?php

namespace App\Http\Controllers\Api\Driver;

use App\Actions\Driver\AcceptOfferAction;
use App\Actions\Driver\CancelTripAction;
use App\Actions\Driver\DeliverOrderAction;
use App\Actions\Driver\PickupOrderAction;
use App\Actions\Driver\RejectOfferAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\CancelTripRequest;
use App\Http\Requests\Driver\DeliverRequest;
use App\Http\Requests\Driver\PickupRequest;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Support\DriverTrip;
use App\Support\Lists\ListSearch;
use App\Support\OrderParties;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §5 — the driver's trip lifecycle. */
class OrderController extends Controller
{
    use FiltersLists;

    public function accept(Request $request, int $order, AcceptOfferAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order);

        return ApiResponse::success(self::forCaptain($order));
    }

    public function reject(Request $request, int $order, RejectOfferAction $action): JsonResponse
    {
        $validated = $request->validate(['reason_code' => ['required', 'in:too_far,busy,vehicle_issue,restaurant_issue,other']]);
        $driver = $request->user()->driver()->firstOrFail();
        $action->execute($driver, $order, $validated['reason_code']);

        return ApiResponse::success(message: 'تم رفض العرض');
    }

    /**
     * The trip's focus — the order of the first stop the captain can act on (doc 63 D-117).
     *
     * Kept for the 1.0.3 app, which reads «the» active order: it sees one order at a time
     * and completes them one by one, and the next one is here when the last is done. The
     * whole trip is `GET /driver/trip`.
     */
    public function active(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        // Re-derived rather than trusted: the stop order depends on where the captain is.
        DriverTrip::refresh($driver);

        if (! $driver->active_order_id) {
            return ApiResponse::success(null);
        }

        $order = Order::query()->find($driver->active_order_id);

        // D-101 — the customer's name, number and address in parts, the place's number
        // and pin, and what to pay and collect, in the blocks every party reads.
        return ApiResponse::success($order === null ? null : OrderParties::presentForCaptain($order));
    }

    public function arrivedRestaurant(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::DriverAssigned], OrderStatus::ArrivedRestaurant, ['arrived_restaurant_at' => now()]);

        return ApiResponse::success(self::forCaptain($order->fresh()));
    }

    public function waiting(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::ArrivedRestaurant], OrderStatus::WaitingAtRestaurant, ['waiting_started_at' => now()]);

        return ApiResponse::success(self::forCaptain($order->fresh()));
    }

    public function pickup(PickupRequest $request, Order $order, PickupOrderAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order, $request->string('otp')->value() ?: null);

        return ApiResponse::success(self::forCaptain($order));
    }

    public function onTheWay(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::PickedUp], OrderStatus::OnTheWay, []);

        return ApiResponse::success(self::forCaptain($order->fresh()));
    }

    public function arrivedCustomer(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::OnTheWay], OrderStatus::ArrivedCustomer, ['arrived_customer_at' => now()]);

        return ApiResponse::success(self::forCaptain($order->fresh()));
    }

    public function deliver(DeliverRequest $request, Order $order, DeliverOrderAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order, $request->string('otp')->value() ?: null, $request->has('collected_amount') ? $request->float('collected_amount') : null);

        return ApiResponse::success(self::forCaptain($order));
    }

    public function cancel(CancelTripRequest $request, Order $order, CancelTripAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order, $request->string('reason_code')->value(), $request->string('note')->value() ?: null);

        return ApiResponse::success(self::forCaptain($order), 'تم إلغاء الرحلة');
    }

    public function issue(Request $request, Order $order): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        abort_unless($order->driver_id === $driver->id, 404);

        // Batch 67 — a finished order stays finished. This moved *any* order the captain
        // had ever carried to `delivery_issue`: a delivered one reopened on every board,
        // in the settlements and in the customer's history.
        if ($order->status->isTerminal()) {
            throw new ApiException('invalid_transition', 'لا يمكن الإبلاغ عن مشكلة في طلب منتهٍ', 422);
        }

        $validated = $request->validate([
            'category' => ['required', 'in:customer_unreachable,wrong_address,accident,food_problem,driver_unresponsive,payment,handover,other'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Batch 67 — was `array` of anything, stored verbatim and rendered as links on
            // the operators' screens. Only https URLs now; the app sends none today.
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['string', 'max:2048', 'url:https'],
        ]);

        $issue = $order->deliveryIssues()->create([
            'reported_by' => 'driver',
            'reporter_id' => $request->user()->id,
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'attachments' => $validated['attachments'] ?? null,
            'status' => 'open',
        ]);

        $order->update(['status' => OrderStatus::DeliveryIssue]);
        $order->statusHistory()->create([
            'from_status' => $order->getOriginal('status'),
            'to_status' => OrderStatus::DeliveryIssue->value,
            'actor_type' => OrderStatusActorType::Driver,
            'actor_id' => $request->user()->id,
        ]);

        return ApiResponse::success($issue, 'تم الإبلاغ عن المشكلة', status: 201);
    }

    /** DRV-28 — one delivered trip, opened directly rather than found in a list. */
    public function show(Request $request, Order $order): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        abort_unless($order->driver_id === $driver->id, 404);

        return ApiResponse::success(OrderParties::presentForCaptain($order));
    }

    /**
     * What «كل الحالات» means on `DRV-27`: the trips that are over.
     *
     * `delivery_issue` is deliberately out — it is an open problem an operator
     * is still working, not a delivery, and the card would file it as one.
     */
    private const HISTORY_STATUSES = ['delivered', 'cancelled'];

    public function history(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'delivered_at', 'driver_earning'], '-created_at');
        $statuses = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        // `filter[from]` is DRV-27's «اليوم / هذا الأسبوع / هذا الشهر» chip, and it is
        // applied here rather than in the app because the two cannot both be right once the
        // list pages: a client that drops rows out of a page it was handed reports a
        // `total` that counts rows it hid, and a range narrow enough to empty a whole page
        // makes the scroll stop on a list that has more behind it.
        //
        // The date compared is the one the trip finished on, falling back to when it was
        // raised for a trip that never did — the same rule the app used, so «اليوم» still
        // holds an order taken at 23:50 and delivered after midnight. «اليوم» on `DRV-27` is
        // the captain's day, which starts at midnight in Gamasa and not at 02:00 there.
        // `date_from` / `date_to` (batch 68) are the same rule with an end.
        $since = $params->date('from');
        [$from, $to] = $params->dates();
        $from = $from ?? $since;

        $status = $params->enum('status', $statuses);

        $query = $driver->orders()->getQuery()
            ->with('restaurant')
            // `DRV-27` is finished work — its own filters are «كل الحالات / المسلَّمة /
            // الملغاة», and its card has exactly two things it can say. Handing it a live
            // order made it say «تم التسليم» over an order the captain had not collected
            // yet, complete with the earning and «لم يقيمك». The order being carried is
            // served by `/driver/orders/active` and drawn by the home screen; this list is
            // the archive.
            ->whereIn('orders.status', $status === [] ? self::HISTORY_STATUSES : $status);

        $this->whereInList($query, 'orders.payment_method', $params->enum('payment_method', ['cash', 'vodafone_cash', 'instapay']));

        $finished = fn (string $op, $moment) => fn ($q) => $q->where(fn ($scope) => $scope
            ->where('orders.delivered_at', $op, $moment)
            ->orWhere(fn ($fallback) => $fallback->whereNull('orders.delivered_at')->where('orders.created_at', $op, $moment)));

        $query->when($from, fn ($q) => $finished('>=', $from)($q))
            ->when($to, fn ($q) => $finished('<=', $to)($q));

        ListSearch::apply($query, $params->search(), [
            'refs' => ['order_number'],
            'names' => ['restaurant_name_snapshot'],
        ]);

        $orders = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($orders, array_map(self::forCaptain(...), $orders->items()), sort: $sort);
    }

    private function transition(Request $request, Order $order, array $allowedFrom, OrderStatus $to, array $extra): void
    {
        $driver = $request->user()->driver()->firstOrFail();
        abort_unless($order->driver_id === $driver->id, 404);

        if (! in_array($order->status, $allowedFrom, true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تنفيذ هذا الإجراء في هذه المرحلة', 422);
        }

        $from = $order->status;
        $order->update([...$extra, 'status' => $to]);

        $order->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => OrderStatusActorType::Driver,
            'actor_id' => $request->user()->id,
        ]);

        $group = $order->orderGroup;
        $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);
    }

    /**
     * Batch 67 — doc 12 §5: the place's commission is the platform's business and the
     * place's, never the captain's. These endpoints answer the raw order row, which
     * carried `commission_rate_snapshot` and `commission_amount` to every captain who
     * touched an order. Nothing in the captain shell reads either.
     */
    private const PLACE_MONEY = ['commission_rate_snapshot', 'commission_amount'];

    private static function forCaptain(mixed $order): mixed
    {
        if ($order instanceof Order) {
            return $order->makeHidden(self::PLACE_MONEY);
        }

        if (is_array($order)) {
            return array_diff_key($order, array_flip(self::PLACE_MONEY));
        }

        return $order;
    }
}
