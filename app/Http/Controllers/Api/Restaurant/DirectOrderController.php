<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Actions\Restaurant\CancelDirectOrderAction;
use App\Actions\Restaurant\CreateDirectOrderAction;
use App\Actions\Restaurant\MarkOrderReadyAction;
use App\Actions\Restaurant\QuoteDirectOrderAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\CreateDirectOrderRequest;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Support\BusinessTime;
use App\Support\Lists\ListSearch;
use App\Support\OrderParties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6.2 — the restaurant buys a delivery, not a marketplace order. */
class DirectOrderController extends Controller
{
    use FiltersLists;

    public function quote(Request $request, QuoteDirectOrderAction $action): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // D-102 — the customer's area, from `GET /areas`. The fee is its price.
            'zone_id' => ['nullable', 'integer'],
        ]);

        $restaurant = $request->user()->restaurant()->firstOrFail();
        $quote = $action->execute(
            $restaurant,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['zone_id']) ? (int) $validated['zone_id'] : null,
        );

        return ApiResponse::success($quote);
    }

    public function store(CreateDirectOrderRequest $request, CreateDirectOrderAction $action): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $order = $action->execute($request->user(), $restaurant, $request->validated(), $request->idempotencyKey());

        return ApiResponse::success([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'origin' => $order->origin,
            'status' => $order->status,
            'prep_due_at' => $order->prep_due_at,
            'seconds_left' => $order->prep_due_at ? max(0, now()->diffInSeconds($order->prep_due_at, false)) : null,
            'subtotal' => (float) $order->subtotal,
            'commission_amount' => (float) $order->commission_amount,
            'declared_order_value' => (float) $order->declared_order_value,
            'delivery_fee' => (float) $order->delivery_fee_share,
            'total' => (float) $order->total,
            // One rule for the door total, the same one the captain's card reads.
            'cash_to_collect' => $order->cashToCollect(),
            'paid_at_pickup' => $order->placePaidAtPickup(),
            'pickup_otp' => $order->getRawOriginal('pickup_otp'),
            'delivery_otp' => null,
        ], 'تم إنشاء الطلب المباشر بنجاح', status: 201);
    }

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'status'], '-created_at');
        $statuses = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        $query = $restaurant->orders()->getQuery()
            ->where('origin', 'restaurant_direct')
            ->with(OrderParties::relations());

        $this->whereInList($query, 'orders.status', $params->enum('status', $statuses));

        // `business_date` is already the place's own calendar day, so the typed dates
        // compare against it as dates.
        [$from, $to] = $params->dates();
        $query->when($from, fn ($q) => $q->where('orders.business_date', '>=', BusinessTime::localDate($from)))
            ->when($to, fn ($q) => $q->where('orders.business_date', '<=', BusinessTime::localDate($to)));

        ListSearch::apply($query, $params->search(), [
            'refs' => ['order_number'],
            'names' => ['guest_customer_name'],
            'phones' => ['guest_customer_phone', 'guest_customer_phone_alt'],
        ]);

        $orders = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($orders, array_map(
            fn (Order $o) => OrderParties::present($o->revealPickupOtp(), 'restaurant'),
            $orders->items(),
        ), sort: $sort);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $order->load(['statusHistory', ...OrderParties::relations()]);

        return ApiResponse::success(OrderParties::present($order->revealPickupOtp(), 'restaurant'));
    }

    public function ready(Request $request, Order $order, MarkOrderReadyAction $action): JsonResponse
    {
        $this->ensureOwned($request, $order);
        $order = $action->execute($request->user(), $order, 'restaurant');

        return ApiResponse::success($order, 'تم تجهيز الطلب وجارٍ البحث عن كابتن');
    }

    public function cancel(Request $request, Order $order, CancelDirectOrderAction $action): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $validated = $request->validate(['reason_code' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:500']]);
        $order = $action->execute($request->user(), $order, $validated['reason_code'], $validated['note'] ?? null);

        return ApiResponse::success($order, 'تم إلغاء الطلب المباشر');
    }

    private function ensureOwned(Request $request, Order $order): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $order->restaurant_id === $restaurant->id && $order->origin->value === 'restaurant_direct', 404);
    }
}
