<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Actions\Restaurant\AcceptOrderAction;
use App\Actions\Restaurant\ExtendPrepTimeAction;
use App\Actions\Restaurant\MarkOrderReadyAction;
use App\Actions\Restaurant\RejectOrderAction;
use App\Actions\Restaurant\StartPreparingAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\AcceptOrderRequest;
use App\Http\Requests\Restaurant\RejectOrderRequest;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6.1 — the board: six queues mapped onto sub-order statuses. */
class OrderController extends Controller
{
    private const QUEUES = [
        'new' => ['pending', 'restaurant_timeout'],
        'awaiting_customer' => ['accepted', 'awaiting_customer_confirmation', 'awaiting_payment'],
        'confirmed' => ['confirmed'],
        'preparing' => ['preparing'],
        'ready' => ['ready', 'searching_driver', 'waiting_for_driver'],
        'with_driver' => ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant', 'picked_up', 'on_the_way', 'arrived_customer'],
        'done' => ['delivered', 'cancelled', 'delivery_issue'],
    ];

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $queue = $request->input('filter.queue');

        $orders = $restaurant->orders()
            ->with(['items.options', 'driver.user'])
            ->when($queue && isset(self::QUEUES[$queue]), fn ($q) => $q->whereIn('status', self::QUEUES[$queue]))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($orders->items(), meta: [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
        ]);
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

        $payload = $order->load(['items.options', 'driver.user', 'statusHistory'])->toArray();
        $payload['awaiting_customer_payment'] = $order->orderGroup->rollup_status === 'awaiting_payment';
        $payload['payment_deadline_at'] = $order->orderGroup->payment?->deadline_at;

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

        if (! in_array($order->status->value, ['ready', 'searching_driver', 'waiting_for_driver', 'driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'], true)) {
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
