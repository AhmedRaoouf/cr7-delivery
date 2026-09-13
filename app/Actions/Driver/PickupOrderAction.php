<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Support\DriverLedger;
use App\Support\Notifications\OperatorAlerts;
use App\Support\Otp;
use App\Support\RollupStatus;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\DB;

/**
 * doc 05 §8.1 — the restaurant's pickup OTP unlocks picked_up and generates the customer's
 * delivery code.
 *
 * doc 62:
 * - **D-108** — the code is checked only when the order was placed with the pickup code
 *   switched on; `orders.pickup_code_required` is snapshotted onto each order so a switch
 *   flipped mid-shift cannot strand one already at the counter. The attempt limit is
 *   `order.otp_max_attempts`, not a literal five, and the lock tells the operators.
 * - **D-103** — this is the moment the captain pays the place on a cash order, so it is
 *   the moment the ledger records the platform owing him that money.
 */
class PickupOrderAction
{
    public function execute(Driver $driver, Order $order, ?string $otp): Order
    {
        abort_unless($order->driver_id === $driver->id, 404);

        if (! in_array($order->status, [OrderStatus::DriverAssigned, OrderStatus::ArrivedRestaurant, OrderStatus::WaitingAtRestaurant], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تنفيذ الاستلام في هذه المرحلة', 422);
        }

        if ((bool) ($order->pickup_code_required ?? true)) {
            $limit = SettingsCatalog::int('order.otp_max_attempts');

            if ($order->pickup_otp_attempts >= $limit) {
                throw new ApiException('otp_attempts_locked', 'تم قفل إدخال الكود، برجاء التواصل مع الدعم', 423);
            }

            if ($otp === null || $otp === '') {
                throw new ApiException('invalid_otp', 'كود الاستلام مطلوب', 422);
            }

            if ($order->getRawOriginal('pickup_otp') !== $otp) {
                $order->increment('pickup_otp_attempts');

                if ($order->pickup_otp_attempts >= $limit) {
                    OperatorAlerts::codeLocked($order, 'pickup');
                }

                throw new ApiException('invalid_otp', 'كود الاستلام غير صحيح', 422, meta: ['attempts_left' => max(0, $limit - $order->pickup_otp_attempts)]);
            }
        }

        return self::complete($driver, $order, OrderStatusActorType::Driver, $driver->user_id);
    }

    /**
     * The half every path to `picked_up` shares — the captain's own and the office's
     * forced one — so the delivery code and the pickup advance cannot be forgotten on
     * either.
     */
    public static function complete(Driver $driver, Order $order, OrderStatusActorType $actor, ?int $actorId): Order
    {
        return DB::transaction(function () use ($driver, $order, $actor, $actorId) {
            $from = $order->status;
            $deliveryOtp = $order->isDirect() || ! (bool) ($order->delivery_code_required ?? true)
                ? null
                : Otp::orderCode();

            $order->update([
                'status' => OrderStatus::PickedUp,
                'picked_up_at' => now(),
                'delivery_otp' => $deliveryOtp,
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::PickedUp->value,
                'actor_type' => $actor,
                'actor_id' => $actorId,
            ]);

            DriverLedger::recordPlaceAdvance($driver, $order);

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            return $order->fresh();
        });
    }
}
