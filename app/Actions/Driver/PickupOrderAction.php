<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Support\Otp;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 05 §8.1 — the restaurant's pickup OTP unlocks picked_up and generates the customer's delivery code. */
class PickupOrderAction
{
    public function execute(Driver $driver, Order $order, string $otp): Order
    {
        abort_unless($order->driver_id === $driver->id, 404);

        if (! in_array($order->status, [OrderStatus::DriverAssigned, OrderStatus::ArrivedRestaurant, OrderStatus::WaitingAtRestaurant], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تنفيذ الاستلام في هذه المرحلة', 422);
        }

        if ($order->pickup_otp_attempts >= 5) {
            throw new ApiException('otp_attempts_locked', 'تم قفل إدخال الكود، برجاء التواصل مع الدعم', 423);
        }

        if ($order->getRawOriginal('pickup_otp') !== $otp) {
            $order->increment('pickup_otp_attempts');

            throw new ApiException('invalid_otp', 'كود الاستلام غير صحيح', 422, meta: ['attempts_left' => 5 - $order->pickup_otp_attempts]);
        }

        return DB::transaction(function () use ($driver, $order) {
            $from = $order->status;
            $deliveryOtp = $order->origin->value === 'restaurant_direct' ? null : Otp::orderCode();

            $order->update([
                'status' => OrderStatus::PickedUp,
                'picked_up_at' => now(),
                'delivery_otp' => $deliveryOtp,
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::PickedUp->value,
                'actor_type' => OrderStatusActorType::Driver,
                'actor_id' => $driver->user_id,
            ]);

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            return $order->fresh();
        });
    }
}
