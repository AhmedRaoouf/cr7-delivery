<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\DispatchService;
use Illuminate\Support\Facades\DB;

/** doc 04 §10 / doc 05 §1 — preparing -> ready triggers dispatch to the nearest 3 drivers. */
class MarkOrderReadyAction
{
    public function __construct(private readonly DispatchService $dispatch)
    {
    }

    public function execute(User $user, Order $order, string $source = 'restaurant'): Order
    {
        if ($order->status !== OrderStatus::Preparing) {
            throw new ApiException('invalid_transition', 'لا يمكن تجهيز الطلب في حالته الحالية', 422);
        }

        return DB::transaction(function () use ($user, $order, $source) {
            $order->update(['status' => OrderStatus::Ready, 'ready_at' => now(), 'ready_source' => $source]);

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Preparing->value,
                'to_status' => OrderStatus::Ready->value,
                'actor_type' => $source === 'timer' ? OrderStatusActorType::System : OrderStatusActorType::Restaurant,
                'actor_id' => $source === 'timer' ? null : $user->id,
            ]);

            $this->dispatch->dispatchRound($order->fresh(), 1);

            return $order->fresh();
        });
    }
}
