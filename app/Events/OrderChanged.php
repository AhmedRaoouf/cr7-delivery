<?php

namespace App\Events;

use App\Models\Order;
use App\Support\BoardQueue;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One order moved. Sent to every party that has a screen open on it.
 *
 * Two design rules, and both matter more than they look:
 *
 * 1. **This is a signal, not a payload.** It carries ids, the two statuses and the
 *    two board queues — never money, never a customer name, never an OTP. A client
 *    that receives it refetches through its own endpoint, which is where the
 *    `role ∩ scope` filter lives (D-50…D-52). So the socket can never hand anyone a
 *    row their HTTP request would have been refused, and out-of-scope stays 404.
 *
 * 2. **`ShouldBroadcastNow`, not `ShouldBroadcast`.** `QUEUE_CONNECTION=database`
 *    and this deployment runs no worker — a queued broadcast would sit in the `jobs`
 *    table forever. Broadcasting inline costs the request one HTTPS POST to Pusher,
 *    capped at `PUSHER_TIMEOUT` (6 s). If a worker is ever added, drop the `Now`.
 *
 * Waiting for the transaction to commit, and swallowing a Pusher outage, are both
 * `OrderObserver`'s job rather than this class's — see the note there.
 *
 * `previousQueue` is what makes the restaurant board cheap: the client invalidates
 * only the column the card left and the column it arrived in, instead of all seven.
 */
class OrderChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly ?string $orderNumber,
        public readonly int $restaurantId,
        public readonly ?int $orderGroupId,
        public readonly ?int $driverId,
        public readonly ?int $cityId,
        public readonly string $status,
        public readonly ?string $previousStatus,
        public readonly string $queue,
        public readonly ?string $previousQueue,
    ) {}

    public static function fromOrder(Order $order, ?string $previousStatus): self
    {
        $status = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;

        return new self(
            orderId: $order->id,
            orderNumber: $order->order_number,
            restaurantId: (int) $order->restaurant_id,
            orderGroupId: $order->order_group_id === null ? null : (int) $order->order_group_id,
            driverId: $order->driver_id === null ? null : (int) $order->driver_id,
            cityId: $order->city_id === null ? null : (int) $order->city_id,
            status: $status,
            previousStatus: $previousStatus,
            queue: BoardQueue::forStatus($status),
            previousQueue: $previousStatus === null ? null : BoardQueue::forStatus($previousStatus),
        );
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('restaurant.'.$this->restaurantId),
        ];

        // Admins are scoped by city (D-51), so the fan-out is per city rather than one
        // global admin channel — a Gamasa sub-admin is never told that a New Mansoura
        // order moved. Orders with no city fall back to a channel only global admins
        // may join.
        $channels[] = new PrivateChannel(
            $this->cityId === null ? 'admin.orders' : 'admin.city.'.$this->cityId
        );

        if ($this->orderGroupId !== null) {
            $channels[] = new PrivateChannel('order-group.'.$this->orderGroupId);
        }

        if ($this->driverId !== null) {
            $channels[] = new PrivateChannel('driver.'.$this->driverId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'restaurant_id' => $this->restaurantId,
            'order_group_id' => $this->orderGroupId,
            'driver_id' => $this->driverId,
            'status' => $this->status,
            'previous_status' => $this->previousStatus,
            'queue' => $this->queue,
            'previous_queue' => $this->previousQueue,
        ];
    }
}
