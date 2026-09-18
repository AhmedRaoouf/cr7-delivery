<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\AdminCancelOrderAction;
use App\Actions\Admin\ForceStatusAction;
use App\Actions\Admin\OfferOrderToDriverAction;
use App\Actions\Admin\ReassignDriverAction;
use App\Actions\Admin\UnassignDriverAction;
use App\Actions\Driver\PickupOrderAction;
use App\Actions\Restaurant\ExtendPrepTimeAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Order;
use App\Support\AdminOrderFilters;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\DispatchService;
use App\Support\DriverTrip;
use App\Support\Lists\ListParams;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\RingService;
use App\Support\Otp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — order management + the doc 24 §3 intervention console. `{id}` addresses a sub-order (orders.id). */
class OrderController extends Controller
{
    use FiltersLists;

    /** What one row of `ADM-04` reads — see `index()`. */
    private const LIST_RELATIONS = [
        'restaurant',
        'driver.user',
        'user:id,name,phone',
        'serviceZone:id,name_ar,name_en',
        'city:id,name_ar,name_en',
        'orderGroup.payment.proofs',
    ];

    public function index(Request $request): JsonResponse
    {
        $sort = AdminOrderFilters::sort($request);

        $orders = $sort->apply(AdminOrderFilters::apply(
            // Batch 68 — every relation `OrderResource` reads, loaded once for the page:
            // `OrderParties::for()` names the customer, the area, the city and the
            // payment, and each of those was a query per row.
            AdminScope::applyToOrders(Order::query(), $request->user())->with(self::LIST_RELATIONS),
            $request,
        ), [])
            ->paginate(ListParams::of($request)->perPage());

        return $this->paginated($orders, OrderResource::collection($orders->getCollection()), sort: $sort);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        return ApiResponse::success(new OrderResource($order->load([
            'restaurant', 'driver.user', 'user', 'items.options', 'statusHistory', 'driverAttempts.driver.user', 'refunds', 'orderGroup.payment.proofs.reviewedBy',
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

        // `ADM-07`'s «اعرض كل الكباتن النشطين». The ranked list is live captains within
        // the radius, which is right for choosing quickly and useless at a moment when
        // every captain is offline — and `ReassignDriverAction` will accept any active,
        // free captain regardless. The operator opts into the wider list explicitly and
        // sees each captain's state on the row.
        $includeOffline = $request->boolean('include_offline');

        $tripMax = DriverTrip::max();

        $drivers = $dispatch->eligibleDrivers($order, $radius, $includeOffline)->map(fn ($c) => [
            'driver_id' => $c['driver']->id,
            'name' => $c['driver']->user->name,
            'distance_km' => $c['distance_km'] === null ? null : round($c['distance_km'], 2),
            'state' => $c['state'],
            // doc 63 §3.6 — «busy» is «carrying something» now, and carrying something no
            // longer rules a captain out: the trip, its room and the gate that would stop
            // dispatch offering them this order are on the row. Only `trip_full` and
            // `on_an_errand` stop the office too; `too_far_for_trip` is the operator's call.
            'is_busy' => $c['trip_count'] > 0,
            'trip_count' => $c['trip_count'],
            'trip_max' => $tripMax,
            'can_take' => $c['refusal'] === null,
            'refusal' => $c['refusal'],
            'last_seen_at' => $c['driver']->location_updated_at?->toIso8601String(),
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

    /**
     * Two ways to hand an order to a captain, and the polite one is the default.
     *
     * `mode = offer` (2026-09-08) sends it as an offer the captain accepts or refuses on
     * `DRV-13`, with a push that says who sent it; a refusal comes back to the operator.
     * `mode = force` is the old behaviour — the order simply becomes theirs, with no
     * notification and no way to say no — and is right for a captain standing in the
     * office and wrong for one whose phone is in a pocket.
     */
    public function reassignDriver(
        Request $request,
        Order $order,
        ReassignDriverAction $action,
        OfferOrderToDriverAction $offer,
    ): JsonResponse {
        $this->ensureInScope($request, $order);

        $validated = $request->validate([
            'driver_id' => ['required', 'exists:drivers,id'],
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
            'mode' => ['nullable', 'in:offer,force'],
        ]);

        if (($validated['mode'] ?? 'offer') === 'force') {
            $order = $action->execute($request->user(), $order, $validated['driver_id'], $validated['reason_code'], $validated['note'] ?? null);

            return ApiResponse::success($order, 'تم إسناد الطلب لكابتن آخر');
        }

        $order = $offer->execute($request->user(), $order, $validated['driver_id'], $validated['reason_code'], $validated['note'] ?? null);

        return ApiResponse::success($order, 'تم إرسال الطلب إلى الكابتن — بانتظار قبوله');
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

        // «إعادة البحث عن كابتن» means everyone, not «whoever has not been asked yet» —
        // an operator presses this precisely because the ones already asked said nothing.
        $dispatch->rebroadcast($order);

        AuditLogger::record($request->user(), 'orders.redispatch', $order);

        return ApiResponse::success($order->fresh(), 'جارٍ البحث عن كابتن مجددًا');
    }

    public function forcePickup(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        if (! in_array($order->status->value, ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن فرض الاستلام في هذه المرحلة', 422);
        }

        // The same completion the captain's own pickup runs: the delivery code (when the
        // order has one) and the pickup advance the captain paid the place (D-103).
        if ($order->driver !== null) {
            $picked = PickupOrderAction::complete($order->driver, $order, OrderStatusActorType::Admin, $request->user()->id);
            $picked->statusHistory()->latest('id')->first()?->update([
                'reason_code' => 'force_pickup',
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);
        } else {
            $from = $order->status;
            $order->update([
                'status' => OrderStatus::PickedUp,
                'picked_up_at' => now(),
                'delivery_otp' => $order->isDirect() || ! (bool) ($order->delivery_code_required ?? true) ? null : Otp::orderCode(),
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::PickedUp->value,
                'actor_type' => 'admin',
                'actor_id' => $request->user()->id,
                'reason_code' => 'force_pickup',
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);
        }

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

        /*
         * 🔴 «إعادة تنبيه المطعم» notified nobody.
         *
         * It incremented a counter and answered «تم إعادة تنبيه المطعم» — an operator
         * ringing a kitchen that never heard a thing, told it worked. The push is the
         * whole point of the button: the same `order.new` the place missed the first
         * time, with the figures it is owed (D-77).
         */
        $fresh = $order->fresh(['items', 'restaurant.owner']);

        // D-100 — with the ring on, the operator's nudge is one more ring on the place's
        // phone, the same one it missed; the schedule is left as it was. A device that
        // cannot ring gets the ordinary push below, as it did before the ring.
        $ringing = RingService::enabled() && $fresh->status === OrderStatus::Pending;

        if ($ringing) {
            app(RingService::class)->ring($fresh, max(1, (int) $fresh->ring_attempts), reschedule: false);
        }

        app(NotificationDispatcher::class)->send(
            $fresh->restaurant?->owner,
            'order.new',
            [
                'order_number' => $fresh->order_number,
                'items' => $fresh->items_count,
                'total' => number_format((float) $fresh->restaurant_amount, 2).' ج.م',
            ],
            deepLink: '/orders/'.$fresh->id,
            orderId: $fresh->id,
            data: ['order_id' => (string) $fresh->id],
            channel: 'offers',
            exceptRinging: $ringing,
        );

        AuditLogger::record($request->user(), $ringing ? 'orders.renotify_restaurant' : 'orders.renotify', $fresh);

        return ApiResponse::success($fresh, 'تم إعادة تنبيه المطعم');
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

    public function raiseIssue(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $validated = $request->validate([
            'category' => ['required', 'in:customer_unreachable,wrong_address,accident,food_problem,driver_unresponsive,payment,handover,other'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $issue = $order->deliveryIssues()->create([
            'reported_by' => 'system',
            'reporter_id' => $request->user()->id,
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'status' => 'open',
        ]);

        $order->update(['status' => OrderStatus::DeliveryIssue]);
        $order->statusHistory()->create([
            'from_status' => $order->getOriginal('status'),
            'to_status' => OrderStatus::DeliveryIssue->value,
            'actor_type' => 'admin',
            'actor_id' => $request->user()->id,
            'meta' => ['message' => 'تدخّلت الإدارة'],
        ]);

        AuditLogger::record($request->user(), 'orders.raise_issue', $order, $validated);

        return ApiResponse::success($issue, 'تم فتح بلاغ مشكلة', status: 201);
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
