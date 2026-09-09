<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\CancelOrderGroupAction;
use App\Actions\Customer\ConfirmOrderGroupAction;
use App\Actions\Customer\CreateOrderAction;
use App\Actions\Customer\RateOrderGroupAction;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelOrderRequest;
use App\Http\Requests\Customer\CreateOrderRequest;
use App\Http\Requests\Customer\RateOrderRequest;
use App\Http\Resources\Customer\OrderGroupCreatedResource;
use App\Http\Resources\Customer\OrderGroupDetailResource;
use App\Http\Resources\Customer\OrderGroupListResource;
use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ApiResponse;
use App\Support\LateCancellationFee;
use App\Support\Locale;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** doc 08 §4 — checkout & orders. `{id}` in every route below is an order_groups.id. */
class OrderController extends Controller
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function store(CreateOrderRequest $request, CreateOrderAction $action): JsonResponse
    {
        $group = $action->execute($request->user(), $request->validated(), $request->idempotencyKey());

        return ApiResponse::success(new OrderGroupCreatedResource($group), 'تم إنشاء الطلب بنجاح', status: 201);
    }

    public function index(Request $request): JsonResponse
    {
        $statusGroup = $request->input('filter.status_group');

        $groups = $request->user()->orderGroups()
            ->with('orders.restaurant')
            ->when($statusGroup, fn ($q) => $q->whereHas('orders', function ($oq) use ($statusGroup) {
                match ($statusGroup) {
                    'active' => $oq->whereNotIn('status', ['delivered', 'cancelled']),
                    'completed' => $oq->where('status', 'delivered'),
                    'cancelled' => $oq->where('status', 'cancelled'),
                    default => null,
                };
            }))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(OrderGroupListResource::collection($groups->getCollection()), meta: [
            'current_page' => $groups->currentPage(),
            'per_page' => $groups->perPage(),
            'total' => $groups->total(),
            'last_page' => $groups->lastPage(),
        ]);
    }

    public function show(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        return ApiResponse::success(new OrderGroupDetailResource(
            $orderGroup->load(['orders.restaurant', 'orders.driver.user', 'orders.items.options', 'orders.statusHistory']),
        ));
    }

    public function tracking(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        $locale = Locale::current($request);
        $orders = $orderGroup->orders()->with(['restaurant', 'driver.user', 'statusHistory'])->get();

        $subOrders = $orders->map(function ($order) use ($locale) {
            $etaMinutes = $order->serviceZone?->avg_delivery_minutes ?? 30;

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'restaurant' => [
                    'id' => $order->restaurant->id,
                    'name' => $order->restaurant->{"name_{$locale}"},
                    'latitude' => (float) $order->restaurant->latitude,
                    'longitude' => (float) $order->restaurant->longitude,
                ],
                'status' => $order->status,
                'status_label' => $order->status->label(),
                'eta_minutes' => $etaMinutes,
                'timeline' => $order->statusHistory->map(fn ($h) => ['status' => $h->to_status, 'at' => $h->created_at, 'done' => true]),
                'driver' => $order->driver ? [
                    'id' => $order->driver->id,
                    'name' => $order->driver->user->name,
                    'phone' => $order->driver->user->phone,
                    'rating_avg' => (float) $order->driver->rating_avg,
                    'vehicle_type' => $order->driver->vehicle_type,
                    'latitude' => $order->driver->hasFix() ? (float) $order->driver->current_lat : null,
                    'longitude' => $order->driver->hasFix() ? (float) $order->driver->current_lng : null,
                    'location_updated_at' => $order->driver->location_updated_at,
                    'is_location_stale' => ! $order->driver->isLive(),
                ] : null,
            ];
        });

        $cancellable = $orders->filter(fn ($order) => $order->status->customerCanCancel());

        return ApiResponse::success([
            'rollup_status' => $orderGroup->rollup_status,
            'rollup_label' => OrderStatus::tryFrom($orderGroup->rollup_status)?->label() ?? $orderGroup->rollup_status,
            'eta_minutes' => $subOrders->min('eta_minutes'),
            'destination' => ['latitude' => (float) $orderGroup->delivery_latitude, 'longitude' => (float) $orderGroup->delivery_longitude],
            // The server draws the cancel button, not the app. The states are
            // one rule (`OrderStatus::customerCanCancel`) and the price is a
            // setting, so an app that decided either for itself would show a
            // button the server refuses or a number the operator has changed.
            'can_cancel' => $cancellable->isNotEmpty(),
            'cancellation_fee_due' => LateCancellationFee::appliesTo($cancellable)
                ? LateCancellationFee::amount()
                : 0.0,
            'sub_orders' => $subOrders,
        ]);
    }

    public function confirmation(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        if (! $orderGroup->confirmation_opened_at) {
            throw new ApiException('confirmation_not_open', 'باب تأكيد الطلب غير مفتوح بعد', 422);
        }

        $locale = Locale::current($request);
        $orders = $orderGroup->orders()->with(['restaurant', 'items.options'])->get();

        return ApiResponse::success([
            'surviving_restaurants' => $orders->reject(fn ($o) => $o->status === OrderStatus::Cancelled)->map(fn ($o) => [
                'restaurant_id' => $o->restaurant_id,
                'restaurant_name' => $o->restaurant->{"name_{$locale}"},
                'prep_minutes' => $o->prep_minutes,
                'items' => $o->items->map(fn ($i) => ['name' => $locale === 'en' ? $i->product_name_en_snapshot : $i->product_name_ar_snapshot, 'quantity' => $i->quantity, 'line_total' => (float) $i->line_total]),
            ])->values(),
            'declined_restaurants' => $orders->where('status', OrderStatus::Cancelled)->map(fn ($o) => [
                'restaurant_id' => $o->restaurant_id,
                'restaurant_name' => $o->restaurant->{"name_{$locale}"},
                'reason_code' => $o->cancellation_reason_code,
            ])->values(),
            'subtotal' => (float) $orderGroup->subtotal,
            'discount_amount' => (float) $orderGroup->discount_amount,
            'delivery_fee' => (float) $orderGroup->delivery_fee,
            'total' => (float) $orderGroup->total,
            'confirm_deadline_at' => $orderGroup->confirm_deadline_at,
            'seconds_left' => $orderGroup->confirm_deadline_at ? max(0, now()->diffInSeconds($orderGroup->confirm_deadline_at, false)) : null,
        ]);
    }

    public function confirm(Request $request, OrderGroup $orderGroup, ConfirmOrderGroupAction $action): JsonResponse
    {
        $group = $action->execute($request->user(), $orderGroup);

        return ApiResponse::success(new OrderGroupCreatedResource($group), 'تم تأكيد الطلب بنجاح');
    }

    public function resendDeliveryCode(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        $order = $orderGroup->orders()->whereNotNull('delivery_otp')->whereIn('status', [
            OrderStatus::DriverAssigned, OrderStatus::ArrivedRestaurant, OrderStatus::WaitingAtRestaurant,
            OrderStatus::PickedUp, OrderStatus::OnTheWay, OrderStatus::ArrivedCustomer,
        ])->first();

        if (! $order) {
            throw new ApiException('order_not_confirmed', 'لا يوجد كود تسليم نشط لهذا الطلب حاليًا', 422);
        }

        $maxResends = (int) (Setting::query()->where('key', 'order.delivery_otp_resend_max')->value('value') ?? 3);

        if ($order->delivery_otp_resends >= $maxResends) {
            throw new ApiException('delivery_otp_resend_limit', 'تم تجاوز الحد الأقصى لإعادة إرسال كود التسليم', 422);
        }

        $order->increment('delivery_otp_resends');

        // This used to be `Log::info("[DELIVERY-OTP] {$order->delivery_otp}")` and
        // nothing else, which was two faults in one line.
        //
        // It **leaked the code**: `TELESCOPE_RECORD_ALL=true` on this deployment writes
        // a row per log entry and the panel is reachable over the network, so the four
        // digits that close any live order sat in readable storage. A code is a secret
        // for as long as the order is open; the log records that it was sent, never what
        // it was.
        //
        // And it **resent nothing**. The button counted against its own three-resend
        // limit (E-41) and the customer's phone stayed silent — the one notification in
        // the system a customer explicitly asks for.
        $this->notifications->send(
            $order->user,
            'order.picked_up',
            ['delivery_otp' => $order->delivery_otp],
            'cr7://order/'.$order->order_group_id,
            $order->id,
            ['order_id' => (string) $order->id, 'group_id' => (string) $order->order_group_id],
        );

        Log::info('delivery code resent', [
            'order_id' => $order->id,
            'resend' => $order->delivery_otp_resends,
        ]);

        return ApiResponse::success(message: 'تم إعادة إرسال كود التسليم');
    }

    public function cancel(CancelOrderRequest $request, OrderGroup $orderGroup, CancelOrderGroupAction $action): JsonResponse
    {
        $result = $action->execute($request->user(), $orderGroup, $request->string('reason_code')->value(), $request->string('note')->value() ?: null);

        return ApiResponse::success([
            'group' => new OrderGroupDetailResource($result['group']->load('orders.restaurant')),
            'cancelled_order_ids' => $result['cancelled'],
            'not_cancelled_order_ids' => $result['not_cancelled'],
            // What it actually cost, so the confirmation the customer reads is
            // the charge that was made rather than the one they were quoted.
            'cancellation_fee' => $result['fee'],
            'outstanding_fees' => (float) $request->user()->fresh()->outstanding_fees,
        ], 'تم إلغاء الطلب');
    }

    public function reorder(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        $user = $request->user();
        $cart = $user->cart()->firstOrCreate([]);
        $added = [];
        $skipped = [];

        foreach ($orderGroup->orders()->with('items.options')->get() as $order) {
            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);

                if (! $product || ! $product->is_available) {
                    $skipped[] = $item->product_name_ar_snapshot;

                    continue;
                }

                $cartItem = $cart->items()->create([
                    'restaurant_id' => $order->restaurant_id,
                    'product_id' => $product->id,
                    'quantity' => $item->quantity,
                    'note' => $item->note,
                ]);

                foreach ($item->options as $option) {
                    if ($option->product_option_id) {
                        $cartItem->options()->create(['product_option_id' => $option->product_option_id]);
                    }
                }

                $added[] = $product->id;
            }
        }

        return ApiResponse::success(['added' => $added, 'skipped' => $skipped], 'تمت إضافة الطلب للسلة');
    }

    public function ratings(RateOrderRequest $request, OrderGroup $orderGroup, RateOrderGroupAction $action): JsonResponse
    {
        $action->execute($request->user(), $orderGroup, $request->validated());

        return ApiResponse::success(message: 'تم إرسال التقييم بنجاح');
    }

    private function ensureOwned(Request $request, OrderGroup $orderGroup): void
    {
        abort_unless($orderGroup->user_id === $request->user()->id, 404);
    }
}
