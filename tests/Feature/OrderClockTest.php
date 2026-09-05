<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\StartPreparingAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Support\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four deadlines the database has always written and nothing ever read.
 *
 * Reported on 2026-09-05 as «the restaurant accepts, the order is ready, and no
 * notification goes anywhere» — which was true, and was two problems wearing one
 * coat. Push had no device tokens (fixed separately), and underneath that the order
 * simply stopped moving: `prep_due_at`, the offer TTL and the restaurant's accept
 * window were all set and never checked, so an order advanced only while a human
 * was pressing buttons.
 */
class OrderClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seedSettings();
    }

    private function seedSettings(): void
    {
        foreach ([
            'dispatch.batch_size' => 3,
            'dispatch.radius_km' => 5,
            'dispatch.radius_expand_km' => 2,
            'dispatch.offer_ttl_seconds' => 30,
            'dispatch.max_rounds' => 3,
            'dispatch.location_stale_seconds' => 90,
            'dispatch.retry_interval_seconds' => 90,
            'restaurant.accept_timeout_minutes' => 5,
        ] as $key => $value) {
            Setting::query()->create(['key' => $key, 'value' => $value, 'group' => 'test']);
        }
    }

    private function makeOrder(Restaurant $restaurant): Order
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        return Order::query()->where('order_group_id', $group->id)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function makeDriver(string $name, array $attributes = []): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => $name]);

        return Driver::query()->create(array_merge([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 31.3841,
            'current_lng' => 31.4841,
            'location_updated_at' => now(),
        ], $attributes));
    }

    // ------------------------------------------------------------ prep timer

    public function test_the_prep_timer_moves_the_order_to_ready_without_the_kitchen(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Confirmed, 'prep_minutes' => 10]);

        app(StartPreparingAction::class)->execute($restaurant->owner, $order->fresh(), '101');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);

        // Nine minutes in: the kitchen's own number has not run out yet.
        $this->travel(9)->minutes();
        $this->artisan('cr7:tick --once')->assertSuccessful();
        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once')->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::WaitingForDriver, $fresh->status, 'ready fires dispatch, which finds nobody');
        $this->assertSame('timer', $fresh->ready_source->value);
    }

    public function test_the_timer_transition_is_attributed_to_the_system_not_the_restaurant(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Confirmed, 'prep_minutes' => 5]);
        app(StartPreparingAction::class)->execute($restaurant->owner, $order->fresh(), '101');

        $this->travel(6)->minutes();
        $this->artisan('cr7:tick --once');

        $row = $order->statusHistory()->where('to_status', 'ready')->firstOrFail();

        $this->assertSame('system', $row->actor_type->value);
        $this->assertNull($row->actor_id);
    }

    public function test_the_restaurant_is_told_its_timer_and_not_its_hand_moved_the_order(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Confirmed, 'prep_minutes' => 5]);
        app(StartPreparingAction::class)->execute($restaurant->owner, $order->fresh(), '101');

        $this->travel(6)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $restaurant->owner->id,
            'type' => 'order.prep_timer_expired',
            'order_id' => $order->id,
        ]);
    }

    // -------------------------------------------------------- accept timeout

    public function test_an_unanswered_order_times_out_and_both_sides_are_told(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);

        $this->assertSame(OrderStatus::Pending, $order->status);

        $this->travel(4)->minutes();
        $this->artisan('cr7:tick --once');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertSame(OrderStatus::RestaurantTimeout, $order->fresh()->status);
        $this->assertDatabaseHas('notifications', ['order_id' => $order->id, 'type' => 'order.timeout']);
        $this->assertDatabaseHas('notifications', ['order_id' => $order->id, 'type' => 'order.timeout_restaurant']);
    }

    public function test_an_order_abandoned_for_days_is_left_alone(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['created_at' => now()->subDays(6)]);

        $this->artisan('cr7:tick --once');

        // Still pending: nobody is sitting by a phone waiting on a six-day-old order,
        // and telling them now is noise, not service.
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // ------------------------------------------------------- dispatch rounds

    public function test_an_empty_round_no_longer_burns_every_round_in_one_instant(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);

        // Nobody online. Before, `dispatchRound` recursed to round 3 in the same
        // millisecond and the widest circle was searched at the narrowest moment.
        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::WaitingForDriver, $fresh->status);
        $this->assertSame(1, $fresh->dispatch_round);
    }

    public function test_a_lapsed_offer_ends_its_round_instead_of_holding_it_open_forever(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);
        $this->makeDriver('كابتن رامي');

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);
        $this->assertSame(OrderStatus::SearchingDriver, $order->fresh()->status);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $order->id, 'result' => 'sent']);

        // The captain never answers. The round's TTL runs out.
        $this->travel(31)->seconds();
        $this->artisan('cr7:tick --once');

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'round' => 1,
            'result' => 'expired',
        ]);
        $this->assertSame(2, $order->fresh()->dispatch_round);
    }

    public function test_a_stranded_order_is_offered_again_when_a_captain_appears(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);
        $this->assertSame(OrderStatus::WaitingForDriver, $order->fresh()->status);

        // A captain comes online two minutes later. This is the whole complaint: nothing
        // ever looked at the order again, and an operator had to reassign it by hand.
        $this->travel(2)->minutes();
        $this->makeDriver('كابتن رامي');

        $this->artisan('cr7:tick --once');

        $this->assertSame(OrderStatus::SearchingDriver, $order->fresh()->status);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $order->id, 'result' => 'sent']);
    }

    public function test_a_retry_forgives_a_lapsed_offer_but_not_a_refusal(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);

        $lapsed = $this->makeDriver('كابتن نام');
        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $order->driverAttempts()->update(['result' => 'expired']);
        $order->update(['status' => OrderStatus::WaitingForDriver]);

        $refused = $this->makeDriver('كابتن رفض');
        $order->driverAttempts()->create([
            'driver_id' => $refused->id,
            'round' => 1,
            'distance_km' => 0.1,
            'result' => 'rejected',
            'sent_at' => now(),
        ]);

        $this->travel(2)->minutes();
        Driver::query()->update(['location_updated_at' => now()]);
        $this->artisan('cr7:tick --once');

        $offered = $order->fresh()->driverAttempts()->where('result', 'sent')->pluck('driver_id');

        $this->assertTrue($offered->contains($lapsed->id), 'a captain whose phone was in a pocket gets asked again');
        $this->assertFalse($offered->contains($refused->id), 'a captain who pressed «رفض» does not');
    }

    public function test_a_stranded_order_does_not_ring_the_same_restaurant_every_minute(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once');
        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertSame(
            1,
            Notification::query()->where('order_id', $order->id)->where('type', 'order.no_driver')->count(),
            'the retry is silent; only the first failure speaks',
        );
    }
}
