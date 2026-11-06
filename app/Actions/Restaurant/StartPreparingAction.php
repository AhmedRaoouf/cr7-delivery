<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\AutoAssign;
use App\Support\EarlyOffer;
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

        $order = DB::transaction(function () use ($user, $order, $restaurantOrderRef) {
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

        // doc 99 §3 — D-171: with less than the lead left on the countdown the nearest
        // captain is assigned now; otherwise `cr7:tick` does it at `prep_due_at − lead`.
        // The place's answer is still «preparing» — the kitchen is not the captain.
        // The kitchen's countdown is committed either way; a failure here is retried by the
        // next tick and must not turn the place's «بدء التحضير» into an error.
        if (AutoAssign::enabled()) {
            try {
                app(AutoAssign::class)->run($order);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('auto-assign at start-preparing failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        } elseif (AutoAssign::offers()) {
            // 2026-11-06 — the same moment, as an offer the captain accepts or refuses.
            try {
                app(EarlyOffer::class)->run($order);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('early offer at start-preparing failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $order;
    }
}
