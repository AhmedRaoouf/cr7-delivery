<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
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
                    'platform_delivery_margin' => round((float) $order->delivery_fee_share - DriverEarningCalculator::calculate($order, $driver), 2),
                ]);

                $driver->update(['is_available' => false, 'active_order_id' => $order->id]);

                $attempt->update(['result' => 'accepted', 'responded_at' => now()]);

                $order->driverAttempts()
                    ->where('driver_id', '!=', $driver->id)
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
