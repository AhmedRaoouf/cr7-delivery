<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\DriverLocationChanged;
use App\Events\OrderChanged;
use App\Http\Controllers\Api\Restaurant\OrderController;
use App\Models\Driver;
use App\Models\Order;
use App\Support\BoardQueue;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

/**
 * The realtime layer replaced seven polled requests every fifteen seconds, per open
 * board, with one socket. What it must never do is become a way to read rows the HTTP
 * API would refuse — so the assertions below are as much about what the payload does
 * *not* carry as about what it does.
 */
class OrderBroadcastTest extends TestCase
{
    private function order(array $attributes = []): Order
    {
        $order = new Order(array_merge([
            'order_number' => 'CR7-1001',
            'restaurant_id' => 3,
            'order_group_id' => 9,
            'driver_id' => null,
            'city_id' => 1,
            'status' => 'preparing',
        ], $attributes));

        $order->id = 77;

        return $order;
    }

    public function test_the_payload_carries_no_customer_or_money_data(): void
    {
        $payload = OrderChanged::fromOrder($this->order(), 'confirmed')->broadcastWith();

        $this->assertSame(
            ['order_id', 'order_number', 'restaurant_id', 'order_group_id', 'driver_id', 'status', 'previous_status', 'queue', 'previous_queue'],
            array_keys($payload),
        );

        foreach (['total', 'subtotal', 'delivery_otp', 'pickup_otp', 'customer_name_snapshot', 'delivery_address_text', 'delivery_contact_phone'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
    }

    public function test_it_names_the_column_the_card_left_and_the_one_it_arrived_in(): void
    {
        $payload = OrderChanged::fromOrder($this->order(['status' => 'ready']), 'preparing')->broadcastWith();

        $this->assertSame('ready', $payload['queue']);
        $this->assertSame('preparing', $payload['previous_queue']);
    }

    public function test_a_new_order_has_no_previous_queue(): void
    {
        $payload = OrderChanged::fromOrder($this->order(['status' => 'pending']), null)->broadcastWith();

        $this->assertSame('new', $payload['queue']);
        $this->assertNull($payload['previous_queue']);
        $this->assertNull($payload['previous_status']);
    }

    public function test_it_reaches_the_restaurant_the_city_admins_and_the_customer(): void
    {
        $names = array_map(
            fn (PrivateChannel $c) => $c->name,
            OrderChanged::fromOrder($this->order(), 'confirmed')->broadcastOn(),
        );

        $this->assertSame([
            'private-restaurant.3',
            'private-admin.city.1',
            'private-order-group.9',
        ], $names);
    }

    public function test_a_scoped_admin_channel_is_never_a_single_global_one(): void
    {
        // D-51: an admin scoped to Gamasa must not be told that a New Mansoura order
        // moved. One `admin.orders` channel for everybody would have leaked exactly that.
        $gamasa = OrderChanged::fromOrder($this->order(['city_id' => 1]), null)->broadcastOn();
        $mansoura = OrderChanged::fromOrder($this->order(['city_id' => 2]), null)->broadcastOn();

        $this->assertNotSame($gamasa[1]->name, $mansoura[1]->name);
    }

    public function test_the_captain_is_added_only_once_an_order_has_one(): void
    {
        $unassigned = OrderChanged::fromOrder($this->order(), null)->broadcastOn();
        $assigned = OrderChanged::fromOrder($this->order(['driver_id' => 5]), null)->broadcastOn();

        $this->assertCount(3, $unassigned);
        $this->assertCount(4, $assigned);
        $this->assertSame('private-driver.5', $assigned[3]->name);
    }

    public function test_every_order_status_maps_onto_exactly_one_queue(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $matches = array_filter(
                BoardQueue::QUEUES,
                fn (array $statuses) => in_array($status->value, $statuses, true),
            );

            $this->assertCount(1, $matches, "{$status->value} belongs to ".count($matches).' queues');
        }
    }

    public function test_the_board_queue_map_is_the_one_the_api_filters_on(): void
    {
        $reflected = new \ReflectionClassConstant(
            OrderController::class, 'QUEUES'
        );

        $this->assertSame(BoardQueue::QUEUES, $reflected->getValue());
    }

    public function test_a_captain_ping_carries_its_coordinates_and_goes_only_where_they_are_allowed(): void
    {
        $driver = new Driver([
            'city_id' => 4,
            'current_lat' => 31.4001,
            'current_lng' => 31.5002,
            'is_online' => true,
            'is_available' => false,
            'location_updated_at' => now(),
        ]);
        $driver->id = 12;

        $event = DriverLocationChanged::fromDriver($driver);
        $payload = $event->broadcastWith();

        // This is the one broadcast in the system that delivers rather than rings, so
        // that a marker can cross a map with no request at all.
        $this->assertSame(31.4001, $payload['lat']);
        $this->assertSame(31.5002, $payload['lng']);

        // And nothing beyond a position: no name, no phone, no plate, no order.
        $this->assertSame(
            ['driver_id', 'lat', 'lng', 'is_online', 'is_available', 'location_updated_at'],
            array_keys($payload),
        );

        // `admin.fleet.{city}` and not `admin.city.{city}`. The wider channel is joinable
        // by a sub-admin who may watch orders move but may not be told where captains
        // are; carrying coordinates onto it would deliver a field
        // `GET /admin/dashboard/live-drivers` refuses. There is no global fallback either
        // — a captain with no city is on nobody's map.
        $names = array_map(
            fn (PrivateChannel $channel) => $channel->name,
            $event->broadcastOn(),
        );

        $this->assertSame(['private-admin.fleet.4'], $names);
    }

    public function test_a_ping_on_a_live_trip_also_reaches_the_customer_watching_it(): void
    {
        $driver = new Driver([
            'city_id' => 4,
            'current_lat' => 31.4,
            'current_lng' => 31.5,
            'is_online' => true,
            'is_available' => false,
        ]);
        $driver->id = 12;
        $driver->setRelation('activeOrder', $this->order(['order_group_id' => 9]));

        $names = array_map(
            fn (PrivateChannel $channel) => $channel->name,
            DriverLocationChanged::fromDriver($driver)->broadcastOn(),
        );

        // The customer's own group channel — which is what lets `CUS-30` draw a moving
        // bike without a `GET /orders/{id}` every five seconds.
        $this->assertSame(['private-admin.fleet.4', 'private-order-group.9'], $names);
    }
}
