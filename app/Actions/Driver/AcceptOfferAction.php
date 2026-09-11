<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderDriverAttempt;
use App\Support\DriverEarningCalculator;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** doc 05 §4 — the race condition. The database decides who gets the order, not the app. */
class AcceptOfferAction
{
    public function execute(Driver $driver, int $orderId): Order
    {
        $lock = Cache::lock("order:{$orderId}:assign", 5);

        if (! $lock->get()) {
            throw new ApiException('already_assigned', 'تم قبول الطلب بواسطة كابتن آخر', 409);
        }

        try {
            return DB::transaction(function () use ($driver, $orderId) {
                $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

                // A captain carries one order at a time. `DispatchService` already
                // excludes a busy captain from the pool, but an offer *already in their
                // hand* when they accepted the previous one stays answerable — and
                // without this the second accept silently overwrote `active_order_id`.
                // The first order kept `driver_id` and `driver_assigned` and became
                // unreachable: `/driver/orders/active` reads `active_order_id`, so the
                // captain could never open it, never deliver it, and nobody was told.
                // `ReassignDriverAction` has always refused this; the accept path never did.
                $active = $driver->fresh()->active_order_id;

                if ($active !== null && $active !== $order->id) {
                    throw new ApiException(
                        'already_on_a_trip',
                        'لديك طلب جارٍ بالفعل — أنهِ التوصيلة الحالية أولًا',
                        409,
                        meta: ['active_order_id' => $active],
                    );
                }

                if ($order->driver_id !== null) {
                    throw new ApiException('already_assigned', 'تم قبول الطلب بواسطة كابتن آخر', 409);
                }

                if ($order->status !== OrderStatus::SearchingDriver) {
                    throw new ApiException('offer_expired', 'انتهت صلاحية هذا العرض', 422);
                }

                $attempt = $driver->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->first();

                if (! $attempt) {
                    throw new ApiException('not_eligible', 'هذا العرض غير متاح لك', 403);
                }

                $order->update([
                    'driver_id' => $driver->id,
                    'status' => OrderStatus::DriverAssigned,
                    'driver_assigned_at' => now(),
                    'driver_earning' => DriverEarningCalculator::calculate($order, $driver),
                    // D-70 — the two halves of the delivery fee, snapshotted together so
                    // moving `delivery.platform_commission_rate` can never re-price a
                    // trip that has already been accepted.
                    'platform_delivery_margin' => DriverEarningCalculator::platformMargin($order, $driver),
                    // A taken order is no longer stranded. Clearing the stamp is what lets
                    // a *second* stranding — this captain cancels, or an operator unassigns
                    // — reach the operators again; it was written once and never reset, so
                    // the second time an order lost its captain nobody was told.
                    'dispatch_admin_notified_at' => null,
                ]);

                $driver->update(['is_available' => false, 'active_order_id' => $order->id]);

                $attempt->update(['result' => 'accepted', 'responded_at' => now()]);

                $order->driverAttempts()
                    ->where('driver_id', '!=', $driver->id)
                    ->where('result', 'sent')
                    ->update(['result' => 'superseded']);

                // …and this captain's open offers on **other** orders, which
                // nothing closed before. It did not matter while offers only
                // ever arrived one at a time; it does now that
                // {@see \App\Support\DispatchService::offerBacklogTo()} hands a
                // captain coming on shift everything unclaimed at once. Left
                // open, the next poll would draw an offer they can no longer
                // take, and «قبول» would answer `already_on_a_trip` — the
                // button that lies, again.
                OrderDriverAttempt::query()
                    ->where('driver_id', $driver->id)
                    ->where('order_id', '!=', $order->id)
                    ->where('result', 'sent')
                    ->update(['result' => 'superseded']);

                $order->statusHistory()->create([
                    'from_status' => OrderStatus::SearchingDriver->value,
                    'to_status' => OrderStatus::DriverAssigned->value,
                    'actor_type' => OrderStatusActorType::Driver,
                    'actor_id' => $driver->user_id,
                ]);

                $group = $order->orderGroup;
                $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

                return $order->fresh();
            });
        } finally {
            $lock->release();
        }
    }
}
