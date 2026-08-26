<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\ConfirmationGateService;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 08 §6 — POST /restaurant/orders/{id}/reject. doc 04 §6.3 — one restaurant declining never cancels the group. */
class RejectOrderAction
{
    public function __construct(private readonly ConfirmationGateService $gate)
    {
    }

    public function execute(User $user, Order $order, string $reasonCode, ?string $note): Order
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::RestaurantTimeout], true)) {
            throw new ApiException('invalid_transition', 'لا يمكن رفض الطلب في حالته الحالية', 422);
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

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            $this->gate->maybeOpen($group, $user);

            return $order->fresh();
        });
    }
}
