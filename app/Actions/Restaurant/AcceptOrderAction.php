<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\ConfirmationGateService;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §6 — POST /restaurant/orders/{id}/accept. doc 04 §8-9 — the restaurant commits
 * to prep_minutes and (optionally now, required by start-preparing) its own POS ref.
 */
class AcceptOrderAction
{
    public function __construct(private readonly ConfirmationGateService $gate)
    {
    }

    public function execute(User $user, Order $order, int $prepMinutes, ?string $restaurantOrderRef): Order
    {
        if ($order->status !== OrderStatus::Pending && $order->status !== OrderStatus::RestaurantTimeout) {
            throw new ApiException('invalid_transition', 'لا يمكن قبول الطلب في حالته الحالية', 422);
        }

        $min = (int) (Setting::query()->where('key', 'restaurant.prep_minutes_min')->value('value') ?? 5);
        $max = (int) (Setting::query()->where('key', 'restaurant.prep_minutes_max')->value('value') ?? 120);

        if ($prepMinutes < $min || $prepMinutes > $max) {
            throw new ApiException('prep_minutes_out_of_range', "مدة التحضير يجب أن تكون بين {$min} و {$max} دقيقة", 422);
        }

        return DB::transaction(function () use ($user, $order, $prepMinutes, $restaurantOrderRef) {
            $from = $order->status;

            $order->update([
                'status' => OrderStatus::Accepted,
                'accepted_at' => now(),
                'accepted_by' => $user->id,
                'prep_minutes' => $prepMinutes,
                'restaurant_order_ref' => $restaurantOrderRef ?: $order->restaurant_order_ref,
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::Accepted->value,
                'actor_type' => OrderStatusActorType::Restaurant,
                'actor_id' => $user->id,
            ]);

            $this->gate->maybeOpen($order->orderGroup, $user);

            return $order->fresh();
        });
    }
}
