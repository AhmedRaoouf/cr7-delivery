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
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\CancelTripRequest;
use App\Http\Requests\Driver\DeliverRequest;
use App\Http\Requests\Driver\PickupRequest;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §5 — the driver's trip lifecycle. */
class OrderController extends Controller
{
    public function accept(Request $request, int $order, AcceptOfferAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order);

        return ApiResponse::success($order);
    }

    public function reject(Request $request, int $order, RejectOfferAction $action): JsonResponse
    {
        $validated = $request->validate(['reason_code' => ['required', 'in:too_far,busy,vehicle_issue,restaurant_issue,other']]);
        $driver = $request->user()->driver()->firstOrFail();
        $action->execute($driver, $order, $validated['reason_code']);

        return ApiResponse::success(message: 'تم رفض العرض');
    }

    public function active(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        if (! $driver->active_order_id) {
            return ApiResponse::success(null);
        }

        $order = Order::with(['restaurant', 'items.options', 'orderGroup.address'])->find($driver->active_order_id);

        return ApiResponse::success($order);
    }

    public function arrivedRestaurant(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::DriverAssigned], OrderStatus::ArrivedRestaurant, ['arrived_restaurant_at' => now()]);

        return ApiResponse::success($order->fresh());
    }

    public function waiting(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::ArrivedRestaurant], OrderStatus::WaitingAtRestaurant, ['waiting_started_at' => now()]);

        return ApiResponse::success($order->fresh());
    }

    public function pickup(PickupRequest $request, Order $order, PickupOrderAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order, $request->string('otp')->value());

        return ApiResponse::success($order);
    }

    public function onTheWay(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::PickedUp], OrderStatus::OnTheWay, []);

        return ApiResponse::success($order->fresh());
    }

    public function arrivedCustomer(Request $request, Order $order): JsonResponse
    {
        $this->transition($request, $order, [OrderStatus::OnTheWay], OrderStatus::ArrivedCustomer, ['arrived_customer_at' => now()]);

        return ApiResponse::success($order->fresh());
    }

    public function deliver(DeliverRequest $request, Order $order, DeliverOrderAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order, $request->string('otp')->value() ?: null, $request->has('collected_amount') ? $request->float('collected_amount') : null);

        return ApiResponse::success($order);
    }

    public function cancel(CancelTripRequest $request, Order $order, CancelTripAction $action): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $order = $action->execute($driver, $order, $request->string('reason_code')->value(), $request->string('note')->value() ?: null);

        return ApiResponse::success($order, 'تم إلغاء الرحلة');
    }

    public function issue(Request $request, Order $order): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        abort_unless($order->driver_id === $driver->id, 404);

        $validated = $request->validate([
            'category' => ['required', 'in:customer_unreachable,wrong_address,accident,food_problem,driver_unresponsive,payment,handover,other'],
            'description' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array'],
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

        return ApiResponse::success($order->load(['restaurant', 'items.options', 'orderGroup.address']));
    }

    public function history(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();

        $orders = $driver->orders()
            ->with('restaurant')
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($orders->items(), meta: [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
        ]);
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
}
