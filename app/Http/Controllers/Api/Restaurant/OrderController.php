<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Actions\Restaurant\AcceptOrderAction;
use App\Actions\Restaurant\ExtendPrepTimeAction;
use App\Actions\Restaurant\MarkOrderReadyAction;
use App\Actions\Restaurant\RejectOrderAction;
use App\Actions\Restaurant\StartPreparingAction;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\AcceptOrderRequest;
use App\Http\Requests\Restaurant\RejectOrderRequest;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Support\BoardQueue;
use App\Support\Lists\ListSearch;
use App\Support\OrderParties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6.1 — the board: six queues mapped onto sub-order statuses. */
class OrderController extends Controller
{
    use FiltersLists;

    /** Moved to App\Support\BoardQueue so the broadcast path reads the same map. */
    private const QUEUES = BoardQueue::QUEUES;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'total', 'status', 'prep_due_at'], '-created_at');
        $statuses = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        // `RES-10` renders a search box, a status select and a date range. Until batch 57
        // only `queue` reached the query, so all three were decoration: an operator
        // narrowing to «تم التسليم» in March got the same unfiltered page back.
        $query = $restaurant->orders()->getQuery()
            ->with(['items.options', ...OrderParties::relations()]);

        // Several queues are the union of their statuses, not an intersection.
        if (($queues = $params->enum('queue', array_keys(self::QUEUES))) !== []) {
            $query->whereIn('orders.status', collect($queues)->flatMap(fn (string $q) => self::QUEUES[$q])->unique()->values()->all());
        }

        $this->whereInList($query, 'orders.status', $params->enum('status', $statuses));
        $this->whereInList($query, 'orders.payment_method', $params->enum('payment_method', ['cash', 'vodafone_cash', 'instapay']));
        $this->whereInList($query, 'orders.origin', $params->enum('origin', ['app', 'restaurant_direct'], ['direct' => 'restaurant_direct']));

        if ($params->bool('overdue') === true) {
            $query->where('orders.status', 'preparing')
                ->whereNotNull('orders.prep_due_at')
                ->where('orders.prep_due_at', '<', now());
        }

        // On `created_at`, not `delivered_at`: the same range has to mean the same thing
        // on a board queue where nothing has been delivered yet. A Cairo day (batch 68 —
        // it was `whereDate` on the UTC column).
        $this->whereBetweenDates($query, 'orders.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'refs' => ['order_number', 'restaurant_order_ref'],
            'names' => ['customer_name_snapshot', 'guest_customer_name'],
            'phones' => ['delivery_contact_phone', 'guest_customer_phone'],
        ]);

        $orders = $sort->apply($query, [])->paginate($params->perPage());

        // `RES-03` reads the code straight off the card. Order::$hidden keeps it off
        // every other payload; this is the one reader entitled to it.
        // D-101 — and each card carries the three parties, so the board and the detail
        // screen read the customer and the captain from the same block.
        $items = array_map(
            fn (Order $o) => OrderParties::present($o->revealPickupOtp()->withRestaurantMoney(), 'restaurant'),
            $orders->items(),
        );

        return $this->paginated($orders, $items, sort: $sort);
    }

    public function newCount(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $counts = [];

        foreach (self::QUEUES as $queue => $statuses) {
            $counts[$queue] = $restaurant->orders()->whereIn('status', $statuses)->count();
        }

        $counts['preparing_overdue'] = $restaurant->orders()
            ->where('status', 'preparing')
            ->whereNotNull('prep_due_at')
            ->where('prep_due_at', '<', now())
            ->count();

        return ApiResponse::success($counts);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $order->load(['items.options', 'statusHistory', ...OrderParties::relations()]);
        $group = $order->orderGroup;

        $payload = OrderParties::present($order->revealPickupOtp()->withRestaurantMoney(), 'restaurant');
        $payload['awaiting_customer_payment'] = $group->rollup_status === 'awaiting_payment';
        $payload['payment_deadline_at'] = $group->payment?->deadline_at;

        return ApiResponse::success($payload);
    }

    public function accept(AcceptOrderRequest $request, Order $order, AcceptOrderAction $action): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $order = $action->execute($request->user(), $order, $request->integer('prep_minutes'), $request->string('restaurant_order_ref')->value() ?: null);

        return ApiResponse::success($order, 'تم قبول الطلب بنجاح');
    }

    public function reject(RejectOrderRequest $request, Order $order, RejectOrderAction $action): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $order = $action->execute($request->user(), $order, $request->string('reason_code')->value(), $request->string('note')->value() ?: null);

        return ApiResponse::success($order, 'تم رفض الطلب');
    }

    public function updateOrderRef(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwned($request, $order);

        if (! in_array($order->status->value, ['confirmed', 'accepted'], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تعديل رقم الطلب في هذه المرحلة', 422);
        }

        $validated = $request->validate(['restaurant_order_ref' => ['required', 'string', 'max:32']]);
        $order->update($validated);

        return ApiResponse::success($order->fresh(), 'تم تحديث رقم الطلب بنجاح');
    }

    public function startPreparing(Request $request, Order $order, StartPreparingAction $action): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $validated = $request->validate(['restaurant_order_ref' => ['nullable', 'string', 'max:32']]);
        $order = $action->execute($request->user(), $order, $validated['restaurant_order_ref'] ?? null);

        return ApiResponse::success([
            'order_id' => $order->id,
            'status' => $order->status,
            'restaurant_order_ref' => $order->restaurant_order_ref,
            'prep_minutes' => $order->prep_minutes,
            'preparing_at' => $order->preparing_at,
            'prep_due_at' => $order->prep_due_at,
            'seconds_left' => max(0, now()->diffInSeconds($order->prep_due_at, false)),
        ]);
    }

    public function extendPrepTime(Request $request, Order $order, ExtendPrepTimeAction $action): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $validated = $request->validate(['extra_minutes' => ['required', 'integer', 'min:1']]);
        $order = $action->execute($order, $validated['extra_minutes']);

        return ApiResponse::success($order, 'تم تمديد وقت التحضير بنجاح');
    }

    public function ready(Request $request, Order $order, MarkOrderReadyAction $action): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $order = $action->execute($request->user(), $order, 'restaurant');

        return ApiResponse::success($order, 'تم تجهيز الطلب وجارٍ البحث عن كابتن');
    }

    public function pickupOtp(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwned($request, $order);

        if (! in_array($order->status->value, Order::PICKUP_OTP_VISIBLE_STATUSES, true)) {
            throw new ApiException('invalid_transition', 'كود الاستلام غير متاح في هذه المرحلة', 422);
        }

        return ApiResponse::success(['pickup_otp' => $order->getRawOriginal('pickup_otp')]);
    }

    private function ensureOwned(Request $request, Order $order): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $order->restaurant_id === $restaurant->id, 404);
    }
}
