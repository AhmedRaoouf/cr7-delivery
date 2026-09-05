<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Support\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «ما جاش للكابتن حاجة… هل هو عشان بعيد ولا إيه؟» — yes, and worse than that.
 *
 * Read off the deployment on 2026-09-05: the only captain on shift was 7.42 km from
 * the restaurant, `is_online` and `is_available` both true, location fresh, no active
 * order. Round 1 searches 5 km and found nobody, which parks the order in
 * `waiting_for_driver` with **zero** attempts. `advanceRounds` only looks at orders
 * with an offer that has expired, so it never saw it; `retryStranded` re-ran **round 1**
 * every ninety seconds. The radius that exists to be widened was re-searched at its
 * narrowest, forever, and an operator had to assign by hand.
 *
 * Two things change. The retry climbs the ladder, and the last rung is a last call with
 * no circle and no batch cap.
 */
class DispatchReachTest extends TestCase
{
    use RefreshDatabase;

    /** The restaurant in Gamasa and the captain 7.42 km out, as measured. */
    private const RESTAURANT = [31.3892245, 31.4720504];

    private const FAR_CAPTAIN = [31.4369403, 31.5267197];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();

        foreach ([
            'dispatch.batch_size' => 3,
            'dispatch.radius_km' => 5,
            'dispatch.radius_expand_km' => 2,
            'dispatch.offer_ttl_seconds' => 30,
            'dispatch.max_rounds' => 3,
            'dispatch.location_stale_seconds' => 90,
            'dispatch.retry_interval_seconds' => 90,
            'dispatch.last_call_all_drivers' => 1,
            // Off here so the rungs below are still the rungs. The broadcast that
            // ships on by default has its own test at the bottom of this file.
            'dispatch.offer_to_all_online' => 0,
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

    /** @param array{0: float, 1: float} $at */
    private function makeDriver(string $name, array $at): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => $name]);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => $at[0],
            'current_lng' => $at[1],
            'location_updated_at' => now(),
        ]);
    }

    public function test_the_first_round_really_does_not_reach_a_captain_seven_kilometres_out(): void
    {
        // Not a defect — the circle is 5 km and this is the measurement that
        // explains the report. It is here so the fix below is not mistaken for
        // one that widened round 1.
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);
        $far = $this->makeDriver('كابتن رامي', self::FAR_CAPTAIN);

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->assertSame(OrderStatus::WaitingForDriver, $order->fresh()->status);
        $this->assertSame(0, $order->driverAttempts()->count());
        $this->assertSame(0, $far->driverAttempts()->count());
    }

    public function test_a_stranded_order_widens_its_circle_instead_of_re_searching_it(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);
        $far = $this->makeDriver('كابتن رامي', self::FAR_CAPTAIN);

        app(DispatchService::class)->dispatchRound($order, 1);
        $this->assertSame(1, $order->fresh()->dispatch_round);

        // Three retries: rounds 2, 3, and 3 again. Round 3 is the last call, so
        // the captain 7.42 km out is asked even though the ladder only reached 9.
        for ($pass = 1; $pass <= 2; $pass++) {
            $this->travel(2)->minutes();
            Driver::query()->update(['location_updated_at' => now()]);
            $this->artisan('cr7:tick --once');
        }

        $this->assertGreaterThan(
            1,
            $order->fresh()->dispatch_round,
            'the retry re-ran round 1 — the radius never grew',
        );
        $this->assertSame(
            1,
            $far->driverAttempts()->where('result', 'sent')->count(),
            'the only captain on shift was never asked',
        );
    }

    public function test_the_last_call_ignores_the_circle_and_the_batch_cap(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);

        // Five captains, every one of them outside even the widest rung.
        $far = collect(range(1, 5))->map(fn (int $n) => $this->makeDriver(
            'كابتن '.$n,
            [self::FAR_CAPTAIN[0] + $n * 0.01, self::FAR_CAPTAIN[1]],
        ));

        app(DispatchService::class)->dispatchRound($order, 3);

        $this->assertSame(
            5,
            $order->fresh()->driverAttempts()->where('result', 'sent')->count(),
            'the last call is capped at the batch size, so two captains never heard',
        );
        $this->assertSame(OrderStatus::SearchingDriver, $order->fresh()->status);
    }

    public function test_the_last_call_can_be_turned_off(): void
    {
        // `settings.value` is a JSON column, so it is rewritten through the model
        // rather than with a raw update.
        Setting::query()->where('key', 'dispatch.last_call_all_drivers')->delete();
        Setting::query()->create([
            'key' => 'dispatch.last_call_all_drivers',
            'value' => false,
            'group' => 'test',
        ]);

        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);
        // Past the widest rung — 7.42 km is *inside* round 3's 9 km circle, which
        // is why the ladder climbing is the fix that mattered and this is only
        // the backstop.
        $this->makeDriver('كابتن رامي', [self::FAR_CAPTAIN[0] + 0.2, self::FAR_CAPTAIN[1]]);

        app(DispatchService::class)->dispatchRound($order, 3);

        $this->assertSame(0, $order->fresh()->driverAttempts()->count());
    }

    public function test_the_nearest_captain_is_still_offered_first(): void
    {
        // The last call must not cost the ranking: whoever would have won a normal
        // round is still the first row written.
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);

        $near = $this->makeDriver('كابتن قريب', [31.3893, 31.4721]);
        $this->makeDriver('كابتن بعيد', self::FAR_CAPTAIN);

        app(DispatchService::class)->dispatchRound($order, 3);

        $first = $order->driverAttempts()->orderBy('id')->first();

        $this->assertSame($near->id, $first->driver_id);
    }

    /**
     * The report that made this the default: «مش مهمة عندي الكابتن فين، المهم إن هو
     * متصل دلوقتي تبعتله notification».
     *
     * With `dispatch.offer_to_all_online` on — which is how it ships — round 1 is
     * already the last call. No clock has to run, no rung has to be climbed, and the
     * captain 7.42 km out is asked in the same request that marked the order ready.
     */
    public function test_every_online_captain_is_asked_in_round_one_when_the_broadcast_is_on(): void
    {
        $this->turnBroadcastOn();

        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);
        $far = $this->makeDriver('كابتن رامي', self::FAR_CAPTAIN);

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->assertSame(OrderStatus::SearchingDriver, $order->fresh()->status);
        $this->assertSame(1, $far->driverAttempts()->where('result', 'sent')->count());
    }

    public function test_the_broadcast_ignores_the_batch_cap(): void
    {
        $this->turnBroadcastOn();

        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);

        collect(range(1, 6))->each(fn (int $n) => $this->makeDriver(
            'كابتن '.$n,
            [self::FAR_CAPTAIN[0] + $n * 0.01, self::FAR_CAPTAIN[1]],
        ));

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->assertSame(6, $order->driverAttempts()->where('result', 'sent')->count());
    }

    /**
     * A captain whose phone has never reported a position is «متصل وفاضي», which is the
     * whole condition. Four of the seven captains on the deployment were in exactly this
     * state, and a freshness gate ahead of the ranking is what emptied the batch.
     */
    public function test_a_captain_who_has_never_sent_a_fix_is_still_offered_the_order(): void
    {
        $this->turnBroadcastOn();

        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);

        $blind = $this->makeDriver('كابتن بلا موقع', self::FAR_CAPTAIN);
        $blind->update(['current_lat' => null, 'current_lng' => null, 'location_updated_at' => null]);

        app(DispatchService::class)->dispatchRound($order, 1);

        $attempt = $blind->driverAttempts()->where('result', 'sent')->first();

        $this->assertNotNull($attempt, 'a captain with no fix was never asked');
        $this->assertNull($attempt->distance_km, 'an unknown distance was written as 0 km');
    }

    /** Distance ranks and never hides — including against a captain who has no distance. */
    public function test_a_captain_with_no_fix_ranks_behind_every_captain_who_has_one(): void
    {
        $this->turnBroadcastOn();

        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);

        $blind = $this->makeDriver('كابتن بلا موقع', self::FAR_CAPTAIN);
        $blind->update(['current_lat' => null, 'current_lng' => null, 'location_updated_at' => null]);
        $far = $this->makeDriver('كابتن رامي', self::FAR_CAPTAIN);

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->assertSame(
            $far->id,
            $order->driverAttempts()->orderBy('id')->first()->driver_id,
            'the captain with no position sorted first — 0.0 was read as a place',
        );
    }

    private function turnBroadcastOn(): void
    {
        Setting::query()->where('key', 'dispatch.offer_to_all_online')->delete();
        Setting::query()->create([
            'key' => 'dispatch.offer_to_all_online',
            'value' => true,
            'group' => 'test',
        ]);
    }
}
