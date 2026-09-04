<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A dispatch round put an offer in front of one captain.
 *
 * This is the event that pays for the whole realtime layer on the mobile side. The
 * captain app polled `GET /driver/offers/current` **every three seconds** for as long as
 * a captain was online and waiting — ten captains on shift is two hundred requests a
 * minute asking "anything for me?", and the answer is no almost every time. With this,
 * the poll becomes a safety net at twenty-five seconds and the offer arrives instantly.
 *
 * Like `OrderChanged` it is a doorbell, not a delivery: the `expires_at` is included
 * because the app needs to size the countdown ring before its fetch lands, and nothing
 * else is. The app calls the same endpoint it always did, so the offer it renders has
 * been through the same authorization.
 */
class DriverOfferSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $driverId,
        public readonly int $orderId,
        public readonly int $round,
        public readonly ?string $expiresAt,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('driver.'.$this->driverId)];
    }

    public function broadcastAs(): string
    {
        return 'offer.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'round' => $this->round,
            'expires_at' => $this->expiresAt,
        ];
    }
}
