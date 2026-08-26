<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\AdminCancelOrderAction;
use App\Actions\Admin\ForceStatusAction;
use App\Actions\Admin\ReassignDriverAction;
use App\Actions\Admin\UnassignDriverAction;
use App\Actions\Restaurant\ExtendPrepTimeAction;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Order;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — order management + the doc 24 §3 intervention console. `{id}` addresses a sub-order (orders.id). */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = AdminScope::applyToOrders(Order::query(), $request->user())
            ->with(['restaurant', 'driver.user'])
            ->when($request->string('filter.origin')->isNotEmpty(), fn ($q) => $q->where('origin', $request->string('filter.origin')))
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->when($request->integer('filter.city_id'), fn ($q, $id) => $q->where('city_id', $id))
            ->when($request->integer('filter.zone_id'), fn ($q, $id) => $q->where('service_zone_id', $id))
            ->when($request->date('filter.date_from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date('filter.date_to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(OrderResource::collection($orders->getCollection()), meta: [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        return ApiResponse::success(new OrderResource($order->load([
            'restaurant', 'driver.user', 'user', 'items.options', 'statusHistory', 'driverAttempts.driver.user', 'refunds', 'orderGroup.payment',
        ])));
    }

    public function attempts(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        return ApiResponse::success($order->driverAttempts()->with('driver.user')->orderByDesc('round')->get());
    }

    public function eligibleDrivers(Request $request, Order $order, DispatchService $dispatch): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $radius = $request->float('radius_km') ?: 10;

        $drivers = $dispatch->eligibleDrivers($order, $radius)->map(fn ($c) => [
            'driver_id' => $c['driver']->id,
            'name' => $c['driver']->user->name,
            'distance_km' => round($c['distance_km'], 2),
            'state' => $c['state'],
            'rating_avg' => (float) $c['driver']->rating_avg,
        ]);

        return ApiResponse::success($drivers);
    }

    public function cancel(Request $request, Order $order, AdminCancelOrderAction $action): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $validated = $request->validate([
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
            'refund' => ['nullable', 'boolean'],
        ]);

        $order = $action->execute($request->user(), $order, $validated['reason_code'], $validated['note'] ?? null, $validated['refund'] ?? false);

        return ApiResponse::success($order, 'تم إلغاء الطلب');
    }

    public function reassignDriver(Request $request, Order $order, ReassignDriverAction $action): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $validated = $request->validate([
            'driver_id' => ['required', 'exists:drivers,id'],
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $action->execute($request->user(), $order, $validated['driver_id'], $validated['reason_code'], $validated['note'] ?? null);

        return ApiResponse::success($order, 'تم إسناد الطلب لكابتن آخر');
    }

    public function unassignDriver(Request $request, Order $order, UnassignDriverAction $action): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $validated = $request->validate([
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
            'requeue' => ['nullable', 'boolean'],
        ]);

        $order = $action->execute($request->user(), $order, $validated['reason_code'], $validated['note'] ?? null, $validated['requeue'] ?? true);

        return ApiResponse::success($order, 'تم إزالة الكابتن من الطلب');
    }

    public function redispatch(Request $request, Order $order, DispatchService $dispatch): JsonResponse
    {
        $this->ensureInScope($request, $order);

        if (! in_array($order->status->value, ['ready', 'searching_driver', 'waiting_for_driver'], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن إعادة البحث عن كابتن في هذه المرحلة', 422);
        }

        $dispatch->dispatchRound($order, $order->dispatch_round + 1);

        AuditLogger::record($request->user(), 'orders.redispatch', $order);

        return ApiResponse::success($order->fresh(), 'جارٍ البحث عن كابتن مجددًا');
    }

    public function forcePickup(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        if (! in_array($order->status->value, ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن فرض الاستلام في هذه المرحلة', 422);
        }

        $from = $order->status;
        $order->update(['status' => OrderStatus::PickedUp, 'picked_up_at' => now(), 'delivery_otp' => (string) random_int(1000, 9999)]);

        $order->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => OrderStatus::PickedUp->value,
            'actor_type' => 'admin',
            'actor_id' => $request->user()->id,
            'reason_code' => 'force_pickup',
            'meta' => ['message' => 'تدخّلت الإدارة'],
        ]);

        AuditLogger::record($request->user(), 'orders.force_pickup', $order);

        return ApiResponse::success($order->fresh(), 'تم فرض حالة الاستلام');
    }

    public function forceStatus(Request $request, Order $order, ForceStatusAction $action): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_column(OrderStatus::cases(), 'value'))],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $order = $action->execute($request->user(), $order, OrderStatus::from($validated['status']), $validated['note']);

        return ApiResponse::success($order, 'تم فرض حالة الطلب');
    }

    public function renotifyRestaurant(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        if (! in_array($order->status->value, ['pending', 'restaurant_timeout'], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن إعادة التنبيه في هذه المرحلة', 422);
        }

        if ($order->renotify_count >= 1) {
            throw new ApiException('renotify_limit_reached', 'تم استخدام محاولة إعادة التنبيه بالفعل', 422);
        }

        $order->increment('renotify_count');
        AuditLogger::record($request->user(), 'orders.renotify', $order);

        return ApiResponse::success($order->fresh(), 'تم إعادة تنبيه المطعم');
    }

    public function extendPrep(Request $request, Order $order, ExtendPrepTimeAction $action): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $validated = $request->validate(['extra_minutes' => ['required', 'integer', 'min:1', 'max:60'], 'note' => ['nullable', 'string', 'max:500']]);

        // doc 04 E-30 — Admin's extension is uncapped and independent of the restaurant's single-use one.
        if ($order->status->value !== 'preparing') {
            throw new ApiException('invalid_transition', 'لا يمكن تمديد الوقت في حالة الطلب الحالية', 422);
        }

        $order->update(['prep_due_at' => $order->prep_due_at->clone()->addMinutes($validated['extra_minutes'])]);
        AuditLogger::record($request->user(), 'orders.extend_prep', $order, ['extra_minutes' => $validated['extra_minutes']]);

        return ApiResponse::success($order->fresh(), 'تم تمديد وقت التحضير');
    }

    public function notes(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        return ApiResponse::success($order->notes()->with('admin')->latest()->get());
    }

    public function addNote(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $validated = $request->validate([
            'kind' => ['required', 'in:note,call'],
            'called_party' => ['nullable', 'in:customer,restaurant,driver'],
            'call_outcome' => ['nullable', 'in:answered,no_answer,wrong_number,switched_off'],
            'body' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array'],
        ]);

        $note = $order->notes()->create([...$validated, 'admin_id' => $request->user()->id]);

        return ApiResponse::success($note, 'تم إضافة الملاحظة', status: 201);
    }

    private function ensureInScope(Request $request, Order $order): void
    {
        abort_unless(
            AdminScope::applyToOrders(Order::query(), $request->user())->whereKey($order->id)->exists(),
            404,
        );
    }
}
