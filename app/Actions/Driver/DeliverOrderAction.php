<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Support\DriverLedger;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 05 §8.2 — the customer's delivery code is the only path to `delivered`, except an origin=restaurant_direct order (D-49), which has none. */
class DeliverOrderAction
{
    public function execute(Driver $driver, Order $order, ?string $otp, ?float $collectedAmount): Order
    {
        abort_unless($order->driver_id === $driver->id, 404);

        if (! in_array($order->status, [OrderStatus::OnTheWay, OrderStatus::ArrivedCustomer], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تسليم الطلب في هذه المرحلة', 422);
        }

        $isDirect = $order->origin->value === 'restaurant_direct';

        if (! $isDirect) {
            if ($order->delivery_otp_attempts >= 5) {
                throw new ApiException('delivery_otp_locked', 'تم قفل إدخال الكود، برجاء التواصل مع الدعم', 423);
            }

            if (! $otp) {
                throw new ApiException('invalid_delivery_otp', 'كود التسليم مطلوب', 422);
            }

            if ($order->getRawOriginal('delivery_otp') !== $otp) {
                $order->increment('delivery_otp_attempts');

                throw new ApiException('invalid_delivery_otp', 'كود التسليم غير صحيح', 422, meta: ['attempts_left' => 5 - $order->delivery_otp_attempts]);
            }
        }

        return DB::transaction(function () use ($driver, $order, $collectedAmount) {
            $from = $order->status;

            $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::Delivered->value,
                'actor_type' => OrderStatusActorType::Driver,
                'actor_id' => $driver->user_id,
            ]);

            $driver->update(['is_available' => true, 'active_order_id' => null, 'completed_orders' => $driver->completed_orders + 1]);

            // D-71 — two meters, never one. `wallet_balance` is the captain's own money;
            // `cash_in_hand` is the platform's money they are carrying. The old code wrote
            // the cash row's `balance_after` from the wallet, so a cash trip logged a
            // wallet figure the wallet had never held.
            DriverLedger::recordEarning($driver, $order);

            if ($order->payment_method->value === 'cash') {
                $cashTotal = $collectedAmount ?? $order->cashToCollect();
                DriverLedger::recordCashCollected($driver, $order, $cashTotal);

                if ($order->orderGroup->payment) {
                    $order->orderGroup->payment->update(['status' => 'paid', 'collected_amount' => $cashTotal, 'paid_at' => now()]);
                }
            }

            $group = $order->orderGroup;
            $group->update([
                'rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status')),
                'completed_at' => $group->orders()->get()->every(fn ($o) => in_array($o->status->value, ['delivered', 'cancelled'], true)) ? now() : null,
            ]);

            return $order->fresh();
        });
    }
}
