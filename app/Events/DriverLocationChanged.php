<?php

namespace App\Events;

use App\Models\Driver;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A captain moved.
 *
 * This one **does** carry its payload, which is the opposite of `OrderChanged`'s first
 * rule, and the difference is deliberate. A doorbell is the right shape when the client
 * can cheaply refetch a list; it is the wrong shape for a marker on a map, because
 * «something moved» forces a full `/admin/dashboard/live-drivers` round trip for a pair
 * of coordinates. The fleet map polled every fifteen seconds for exactly that, whether
 * or not anybody had moved, and it is the screen an operator leaves open all day.
 *
 * Carrying the payload is only safe because the channel is narrower than
 * `admin.city.{id}`: `admin.fleet.{cityId}` additionally requires
 * `drivers.view_location`, the same permission that gates
 * `GET /admin/dashboard/live-drivers`. A sub-admin who may see that orders moved in
 * their city but may not see where captains are can join the first channel and not this
 * one, so the socket still cannot deliver a field the HTTP endpoint would have refused.
 *
 * The customer's own tracking screen is served by the second channel:
 * `order-group.{groupId}`, and only while this captain is actually carrying that group.
 * That is what lets the app draw a moving bike without a `GET /orders/{id}` every few
 * seconds.
 *
 * **Throttled at the caller, not here.** A phone pings every few seconds and a broadcast
 * per ping would spend the plan's message budget on nothing a human eye can see — the
 * cadence lives in `DriverMeController::location`.
 */
class DriverLocationChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $driverId,
        public readonly ?int $cityId,
        public readonly ?int $orderGroupId,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly bool $isOnline,
        public readonly bool $isAvailable,
        public readonly string $updatedAt,
        /** @var list<int> every other basket on the captain's trip (doc 63 D-116) */
        public readonly array $tripGroupIds = [],
    ) {}

    /** @param list<int> $tripGroupIds the baskets on the captain's trip besides the focus's */
    public static function fromDriver(Driver $driver, array $tripGroupIds = []): self
    {
        return new self(
            driverId: $driver->id,
            cityId: $driver->city_id === null ? null : (int) $driver->city_id,
            orderGroupId: $driver->activeOrder?->order_group_id === null
                ? null
                : (int) $driver->activeOrder->order_group_id,
            latitude: (float) $driver->current_lat,
            longitude: (float) $driver->current_lng,
            isOnline: (bool) $driver->is_online,
            isAvailable: (bool) $driver->is_available,
            updatedAt: ($driver->location_updated_at ?? now())->toIso8601String(),
            tripGroupIds: array_values(array_map('intval', $tripGroupIds)),
        );
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        $channels = [];

        // A captain with no city is not on anyone's fleet map; there is no global
        // fallback channel here on purpose, because coordinates are not something to
        // fan out more widely than the row they came from.
        if ($this->cityId !== null) {
            $channels[] = new PrivateChannel('admin.fleet.'.$this->cityId);
        }

        $groups = array_values(array_unique(array_filter([$this->orderGroupId, ...$this->tripGroupIds])));

        foreach ($groups as $groupId) {
            $channels[] = new PrivateChannel('order-group.'.$groupId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'driver.location';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'driver_id' => $this->driverId,
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'is_online' => $this->isOnline,
            'is_available' => $this->isAvailable,
            'location_updated_at' => $this->updatedAt,
        ];
    }
}
