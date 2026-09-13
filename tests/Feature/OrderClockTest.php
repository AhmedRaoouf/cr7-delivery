<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\StartPreparingAction;
use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderDriverAttempt;
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
            // `updateOrCreate`: a migration may already have written the catalogue's
            // default for some of these keys (doc 62, D-113).
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'test']);
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

    /**
     * The 2026-09-08 reversal: an offer has no clock, and the *search* is what moves.
     *
     * This test used to assert the opposite — that a captain who said nothing for
     * thirty-one seconds had their offer marked `expired` and the round advanced. The
     * operator ruled against it: «ما يكونش 30 ثانية، هو يكون موجود لحد ما يرفض أو
     * يقبل». So the row stays `sent`, the «قبول» button is still live on the captain's
     * phone, and what happens on the clock instead is that more captains are asked.
     */
    public function test_an_unanswered_offer_stays_open_while_the_search_widens(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);
        $rami = $this->makeDriver('كابتن رامي');

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);
        $this->assertSame(OrderStatus::SearchingDriver, $order->fresh()->status);
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $rami->id,
            'result' => 'sent',
            'expires_at' => null,
        ]);

        // A minute passes with no answer, and a second captain comes on shift.
        $this->travel(61)->seconds();
        $sara = $this->makeDriver('كابتن سارة');
        $this->artisan('cr7:tick --once');

        // Rami's offer is untouched — still his to take.
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $rami->id,
            'result' => 'sent',
        ]);
        // …and Sara has been asked as well.
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $sara->id,
            'result' => 'sent',
        ]);
        // The order never left the search while that happened.
        $this->assertSame(OrderStatus::SearchingDriver, $order->fresh()->status);
    }

    /** A captain holding an open offer is never rung twice for the same order. */
    public function test_widening_does_not_re_offer_to_a_captain_who_already_holds_one(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);
        $rami = $this->makeDriver('كابتن رامي');

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->travel(61)->seconds();
        $this->artisan('cr7:tick --once');
        $this->travel(61)->seconds();
        $this->artisan('cr7:tick --once');

        $this->assertSame(1, OrderDriverAttempt::query()
            ->where('order_id', $order->id)
            ->where('driver_id', $rami->id)
            ->count());
    }

    /**
     * With no TTL, «ran out of rounds» can no longer be the thing that reaches an
     * operator — so the escalation is on its own clock and says it once.
     */
    public function test_an_order_nobody_takes_reaches_the_operators_once(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);
        $this->makeDriver('كابتن رامي');

        $admin = User::factory()->create(['type' => UserType::Admin, 'is_active' => true]);
        // D-51 — only the operators whose scope covers the order are told, so a test
        // admin needs one. A global scope is what the super admin carries.
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->travel(6)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'order.unclaimed_admin',
        ]);
        $this->assertNotNull($order->fresh()->dispatch_admin_notified_at);

        // A sweep that runs every minute must not send a push every minute.
        $this->travel(6)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertSame(1, Notification::query()
            ->where('user_id', $admin->id)
            ->where('type', 'order.unclaimed_admin')
            ->count());
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

    /**
     * D-76 — «يبعت للناس كلها تاني، المتصلة والمتفرغة، لحد ما حد يقبل».
     *
     * This test used to assert the opposite of its second half: a captain who declined
     * was excluded for ever. The operator ruled on 2026-09-11 that two minutes later
     * everybody is asked again, a refusal included — a «رفض» at 19:03 was about 19:03,
     * and the alternative is an order that nobody in town is allowed to take.
     */
    public function test_every_free_captain_is_asked_again_two_minutes_later(): void
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

        $this->travel(3)->minutes();
        Driver::query()->update(['location_updated_at' => now()]);
        $this->artisan('cr7:tick --once');

        $offered = $order->fresh()->driverAttempts()->where('result', 'sent')->pluck('driver_id');

        $this->assertTrue($offered->contains($lapsed->id), 'a captain whose phone was in a pocket gets asked again');
        $this->assertTrue($offered->contains($refused->id), 'and so does one who declined the last round');
    }

    /** One captain, one live offer — a re-ring, never a second row to accept twice. */
    public function test_a_captain_holding_an_offer_is_rung_again_not_offered_twice(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);

        $driver = $this->makeDriver('كابتن منتظر');
        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->assertSame(1, $order->driverAttempts()->where('driver_id', $driver->id)->count());

        $this->travel(3)->minutes();
        Driver::query()->update(['location_updated_at' => now()]);
        $this->artisan('cr7:tick --once');

        $this->assertSame(
            1,
            $order->driverAttempts()->where('driver_id', $driver->id)->where('result', 'sent')->count(),
            'the offer in their hand stays the only one',
        );
    }

    /**
     * D-76 — the kitchen is told once, at two minutes, and told what to do about it.
     *
     * It used to be told the moment the dispatcher ran out of *new* captains to ask,
     * which can be the same second the food was marked ready, and the customer was told
     * as well («لم نجد كابتنًا متاحًا لطلبك») — which is our problem, not theirs.
     */
    public function test_the_place_is_told_once_at_two_minutes_and_the_customer_never(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Preparing]);

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        // One minute in: nothing yet. The food has barely reached the counter.
        $this->travel(1)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertSame(0, Notification::query()
            ->where('order_id', $order->id)
            ->where('type', 'order.no_driver_restaurant')
            ->count());

        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once');
        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertSame(
            1,
            Notification::query()
                ->where('order_id', $order->id)
                ->where('type', 'order.no_driver_restaurant')
                ->count(),
            'said once, not once a minute',
        );

        $this->assertSame(
            0,
            Notification::query()->where('order_id', $order->id)->where('type', 'order.no_driver')->count(),
            'the customer is never told about our search',
        );
    }
}
