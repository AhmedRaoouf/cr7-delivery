<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** doc 08 §6.2 — cancellable only up to driver_assigned (D-49). */
class CancelDirectOrderAction
{
    private const BLOCKED_FROM = ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant', 'picked_up', 'on_the_way', 'arrived_customer', 'delivered', 'cancelled'];

    public function execute(User $user, Order $order, string $reasonCode, ?string $note): Order
    {
        if (in_array($order->status->value, self::BLOCKED_FROM, true)) {
            throw new ApiException('direct_order_not_cancellable', 'لا يمكن إلغاء الطلب المباشر بعد إسناده لكابتن', 422);
        }

        return DB::transaction(function () use ($user, $order, $reasonCode, $note) {
            $from = $order->status;

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_by' => 'restaurant',
                'cancellation_reason_code' => $reasonCode,
                'cancellation_note' => $note,
                'cancelled_at' => now(),
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::Cancelled->value,
                'actor_type' => OrderStatusActorType::Restaurant,
                'actor_id' => $user->id,
                'reason_code' => $reasonCode,
                'note' => $note,
            ]);

            $order->orderGroup->update(['rollup_status' => 'cancelled', 'completed_at' => now()]);

            return $order->fresh();
        });
    }
}
