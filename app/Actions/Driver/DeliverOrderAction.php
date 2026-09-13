<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Support\DispatchService;
use App\Support\DriverLedger;
use App\Support\Notifications\OperatorAlerts;
use App\Support\RollupStatus;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\DB;

/**
 * doc 05 §8.2 — the customer's delivery code is the only path to `delivered`, except an
 * origin=restaurant_direct order (D-49), which has none, and — doc 62 D-108 — an order
 * placed while the operator had the delivery code switched off.
 */
class DeliverOrderAction
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function execute(Driver $driver, Order $order, ?string $otp, ?float $collectedAmount): Order
    {
        abort_unless($order->driver_id === $driver->id, 404);

        if (! in_array($order->status, [OrderStatus::OnTheWay, OrderStatus::ArrivedCustomer], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تسليم الطلب في هذه المرحلة', 422);
        }

        // D-49 — a direct order has no delivery code; D-108 — nor has an order placed with
        // the delivery code switched off. The snapshot on the order decides, not today's
        // switch.
        $codeRequired = ! $order->isDirect() && (bool) ($order->delivery_code_required ?? true);

        if ($codeRequired) {
            $limit = SettingsCatalog::int('order.otp_max_attempts');

            if ($order->delivery_otp_attempts >= $limit) {
                throw new ApiException('delivery_otp_locked', 'تم قفل إدخال الكود، برجاء التواصل مع الدعم', 423);
            }

            if (! $otp) {
                throw new ApiException('invalid_delivery_otp', 'كود التسليم مطلوب', 422);
            }

            if ($order->getRawOriginal('delivery_otp') !== $otp) {
                $order->increment('delivery_otp_attempts');

                if ($order->delivery_otp_attempts >= $limit) {
                    OperatorAlerts::codeLocked($order, 'delivery');
                }

                throw new ApiException('invalid_delivery_otp', 'كود التسليم غير صحيح', 422, meta: ['attempts_left' => max(0, $limit - $order->delivery_otp_attempts)]);
            }
        }

        $delivered = self::complete($driver, $order, $collectedAmount, OrderStatusActorType::Driver, $driver->user_id);

        // Outside the transaction on purpose: writing offer rows for other
        // orders inside this one would hold locks across them, and a captain who
        // has just handed over a bag should hear about the next job in the same
        // second — «مفيش طلب يبقى من غير كابتن وبأسرع ما يمكن».
        $this->dispatch->offerBacklogTo($driver);

        return $delivered;
    }

    /**
     * Everything `delivered` owes the ledger — shared with the office's forced
     * `delivered`, which used to write the status and nothing else: no earning, no cash
     * row, and a captain who had carried the food was paid for it by nobody.
     */
    public static function complete(Driver $driver, Order $order, ?float $collectedAmount, OrderStatusActorType $actor, ?int $actorId): Order
    {
        return DB::transaction(function () use ($driver, $order, $collectedAmount, $actor, $actorId) {
            $from = $order->status;

            $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::Delivered->value,
                'actor_type' => $actor,
                'actor_id' => $actorId,
            ]);

            $driver->update(['is_available' => true, 'active_order_id' => null, 'completed_orders' => $driver->completed_orders + 1]);

            // D-103 — a pickup that was forced, or that predates the advance, still owes
            // the captain the food money he handed the place. Idempotent.
            DriverLedger::recordPlaceAdvance($driver, $order);

            // D-71 — two meters, never one. `wallet_balance` is the captain's own money;
            // `cash_in_hand` is the platform's money they are carrying.
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
