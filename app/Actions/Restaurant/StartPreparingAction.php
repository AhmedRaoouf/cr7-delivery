<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** doc 08 §6 — POST /restaurant/orders/{id}/start-preparing: confirmed -> preparing, starts the countdown. */
class StartPreparingAction
{
    public function execute(User $user, Order $order, ?string $restaurantOrderRef): Order
    {
        if ($order->status !== OrderStatus::Confirmed) {
            throw new ApiException('invalid_transition', 'الطلب ليس في حالة تسمح ببدء التحضير', 409);
        }

        if (! $restaurantOrderRef && ! $order->restaurant_order_ref) {
            throw new ApiException('restaurant_order_ref_required', 'رقم الطلب في نظام المطعم مطلوب', 422);
        }

        return DB::transaction(function () use ($user, $order, $restaurantOrderRef) {
            $preparingAt = now();

            $order->update([
                'status' => OrderStatus::Preparing,
                'preparing_at' => $preparingAt,
                'prep_due_at' => $preparingAt->clone()->addMinutes($order->prep_minutes),
                'restaurant_order_ref' => $restaurantOrderRef ?: $order->restaurant_order_ref,
            ]);

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Confirmed->value,
                'to_status' => OrderStatus::Preparing->value,
                'actor_type' => OrderStatusActorType::Restaurant,
                'actor_id' => $user->id,
            ]);

            return $order->fresh();
        });
    }
}
