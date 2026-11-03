<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Events\OrderChanged;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\BoardQueue;
use App\Support\DispatchService;
use App\Support\Notifications\OrderNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * doc 04 §10 / doc 05 §1 — preparing -> ready triggers dispatch to the nearest 3 drivers.
 *
 * doc 99 §3 — **the kitchen is not the captain.** An order that already has its captain
 * while it cooks (auto-assign ten minutes before it is due, or the office forcing one) is
 * `driver_assigned` with `ready_at = null`. «جاهز» and the prep timer then set `ready_at`
 * without touching the status — the captain has the order already — and tell him.
 */
class MarkOrderReadyAction
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function execute(User $user, Order $order, string $source = 'restaurant'): Order
    {
        if (BoardQueue::kitchenPending($order)) {
            return $this->readyOnTheCaptainsTrip($order, $source);
        }

        if ($order->status !== OrderStatus::Preparing) {
            throw new ApiException('invalid_transition', 'لا يمكن تجهيز الطلب في حالته الحالية', 422);
        }

        return DB::transaction(function () use ($user, $order, $source) {
            $order->update([
                'status' => OrderStatus::Ready,
                'ready_at' => now(),
                'ready_source' => $source,
                'prep_actual_seconds' => self::prepSeconds($order),
            ]);

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

    private function readyOnTheCaptainsTrip(Order $order, string $source): Order
    {
        $fresh = DB::transaction(function () use ($order, $source) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            // A second «جاهز», or the timer a second after the hand: nothing left to do.
            if (! BoardQueue::kitchenPending($locked)) {
                throw new ApiException('invalid_transition', 'لا يمكن تجهيز الطلب في حالته الحالية', 422);
            }

            $locked->update([
                'ready_at' => now(),
                'ready_source' => $source,
                'prep_actual_seconds' => self::prepSeconds($locked),
            ]);

            return $locked;
        });

        app(OrderNotifications::class)->kitchenReady($fresh);
        self::announceTheBoardMove($fresh);

        return $fresh->fresh();
    }

    /**
     * The card moves from «قيد التحضير» to «مع الكابتن» without a status change, which the
     * observer does not announce (it watches `status` and `driver_id`).
     */
    private static function announceTheBoardMove(Order $order): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        try {
            broadcast(OrderChanged::fromOrder($order, $order->status->value, previousQueue: 'preparing'));
        } catch (\Throwable $e) {
            Log::warning('order broadcast failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private static function prepSeconds(Order $order): ?int
    {
        return $order->preparing_at === null ? null : max(0, (int) $order->preparing_at->diffInSeconds(now()));
    }
}
