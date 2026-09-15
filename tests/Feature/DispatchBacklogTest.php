<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Support\DispatchService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «مفيش طلب يبقى من غير كابتن وبأسرع ما يمكن» — the captain-driven half of dispatch.
 *
 * Every path into {@see DispatchService} was order-driven: an order turns `ready`
 * and asks who is around. Nothing ran the other way, so a captain who came online
 * a minute after that single round was never asked about the order again until
 * {@see \App\Console\Commands\TickCommand}'s ‪120‬-second sweep came round.
 *
 * On this deployment that sweep has **never run**. `php artisan schedule:list`
 * shows `cr7:tick` due every minute; `storage/logs/cron.log` is ‪0‬ bytes and was
 * last written on 2026-09-05. hPanel's cron entry is not firing, so the order
 * machine has had no clock for a week — which is why a captain sat online while
 * an order the kitchen had marked ready found nobody.
 *
 * Fixing the cron is an operator action. These tests cover the half that should
 * never have depended on it: a captain becoming free is itself an event, and the
 * backlog goes to them then and there.
 */
class DispatchBacklogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    public function test_a_captain_coming_online_is_handed_the_orders_nobody_took(): void
    {
        [$driver, $order] = $this->strandedOrderAndFreeCaptain();

        $this->assertSame(0, $driver->driverAttempts()->count(), 'Precondition: nobody has been asked yet.');

        $offered = app(DispatchService::class)->offerBacklogTo($driver);

        $this->assertSame(1, $offered);
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
        ]);
    }

    public function test_the_status_endpoint_hands_over_the_backlog_on_the_way_in(): void
    {
        [$driver, $order] = $this->strandedOrderAndFreeCaptain();
        $driver->update(['is_online' => false]);

        $this->asCaptain($driver)
            ->postJson('/api/v1/driver/status', ['is_online' => true])
            ->assertOk();

        // The captain hears about it on the way in, not two minutes later.
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
        ]);
    }

    public function test_a_stale_trip_lock_no_longer_bars_a_captain_from_their_own_shift(): void
    {
        [$driver, $order] = $this->strandedOrderAndFreeCaptain();

        // The exact wedge: an order that ended by a path which never cleared the
        // lock. `active_order_id` then fails the busy check in
        // `DriverMeController::status`, so the captain cannot go online — so can
        // never take a trip — so nothing ever clears it. Permanent, and the app
        // keeps showing «أنت متصل الآن» because that reads `is_online`.
        $finished = $this->strandedOrder('سندوتش عمر');
        $finished->update(['status' => OrderStatus::Delivered]);
        $driver->update(['is_online' => false, 'is_available' => false, 'active_order_id' => $finished->id]);

        $this->asCaptain($driver)
            ->postJson('/api/v1/driver/status', ['is_online' => true])
            ->assertOk()
            ->assertJsonPath('data.is_online', true);

        $driver->refresh();
        $this->assertNull($driver->active_order_id);
        $this->assertTrue($driver->is_available);
        // …and the repair is invisible: they simply get the backlog like anyone else.
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
        ]);
    }

    public function test_a_refusal_is_answered_with_the_next_order_not_the_same_one(): void
    {
        [$driver, $first] = $this->strandedOrderAndFreeCaptain();
        $second = $this->strandedOrder();

        $dispatch = app(DispatchService::class);
        $dispatch->offerBacklogTo($driver);

        $this->asCaptain($driver)
            ->postJson("/api/v1/driver/orders/{$first->id}/reject", ['reason_code' => 'busy'])
            ->assertOk();

        // The one they turned down stays turned down — the backlog path fires
        // in the same second as the refusal, so re-offering it would just be the
        // same offer again. (A later `rebroadcast` may still ask; that is a
        // different ruling and a different amount of elapsed time.)
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $first->id, 'driver_id' => $driver->id, 'result' => 'rejected',
        ]);
        $this->assertSame(
            0,
            $driver->driverAttempts()->where('order_id', $first->id)->where('result', 'sent')->count(),
        );
    }

    /**
     * doc 63 §2.2, restating what this test guarded since 2026-09-12: accepting one order
     * re-checks every other offer in the captain's hand against the trip they now carry.
     * One that still fits stays open — «يجيله أكتر من order مع بعض» — and is on the next
     * poll with the trip it would join.
     */
    public function test_accepting_one_order_keeps_this_captains_offers_that_still_fit_the_trip(): void
    {
        [$driver, $first] = $this->strandedOrderAndFreeCaptain();
        $second = $this->strandedOrder();

        app(DispatchService::class)->offerBacklogTo($driver);
        $this->assertSame(2, $driver->driverAttempts()->where('result', 'sent')->count());

        $this->asCaptain($driver)
            ->postJson("/api/v1/driver/orders/{$first->id}/accept")
            ->assertOk();

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $second->id, 'driver_id' => $driver->id, 'result' => 'sent',
        ]);

        $this->asCaptain($driver)
            ->getJson('/api/v1/driver/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $second->id)
            ->assertJsonPath('data.0.trip.count', 1)
            ->assertJsonPath('data.0.trip.after', 2)
            ->assertJsonPath('data.0.trip.max', 3);
    }

    /**
     * …and one that no longer fits is closed. Left open, the next poll draws an offer whose
     * «قبول» can only refuse — and the app shows `offers.first`, so one such row is enough
     * to wedge the screen behind a button that cannot work.
     */
    public function test_accepting_one_order_closes_this_captains_offers_that_no_longer_fit(): void
    {
        [$driver, $first] = $this->strandedOrderAndFreeCaptain();
        $full = $this->strandedOrder();
        // Eight kilometres from the first place: outside the 1.5 km that joins a trip.
        $far = $this->strandedOrder('كنج بروست', 31.4560, 31.4840);

        app(DispatchService::class)->offerBacklogTo($driver);
        $this->assertSame(3, $driver->driverAttempts()->where('result', 'sent')->count());

        Settings::set('dispatch.max_orders_per_trip', 1);

        $this->asCaptain($driver)
            ->postJson("/api/v1/driver/orders/{$first->id}/accept")
            ->assertOk();

        foreach ([$full, $far] as $order) {
            $this->assertDatabaseHas('order_driver_attempts', [
                'order_id' => $order->id, 'driver_id' => $driver->id, 'result' => 'superseded',
            ]);
        }

        $this->asCaptain($driver)
            ->getJson('/api/v1/driver/offers')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_an_offer_whose_place_is_far_from_the_trip_is_closed_on_accept(): void
    {
        [$driver, $first] = $this->strandedOrderAndFreeCaptain();
        $near = $this->strandedOrder();
        $far = $this->strandedOrder('كنج بروست', 31.4560, 31.4840);

        app(DispatchService::class)->offerBacklogTo($driver);

        $this->asCaptain($driver)
            ->postJson("/api/v1/driver/orders/{$first->id}/accept")
            ->assertOk();

        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $near->id, 'driver_id' => $driver->id, 'result' => 'sent']);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $far->id, 'driver_id' => $driver->id, 'result' => 'superseded']);
    }

    public function test_an_order_that_has_since_found_a_captain_is_not_still_an_offer(): void
    {
        [$driver, $order] = $this->strandedOrderAndFreeCaptain();
        app(DispatchService::class)->offerBacklogTo($driver);

        // Somebody else took it. The `sent` row was never superseded on any path
        // but the accepting captain's own, so this poll used to answer with an
        // order that already had a driver.
        $order->update([
            'driver_id' => $this->makeFreeCaptain('كابتن آخر')->id,
            'status' => OrderStatus::DriverAssigned,
        ]);

        $this->asCaptain($driver)
            ->getJson('/api/v1/driver/offers')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /** @return array{0: Driver, 1: Order} */
    private function strandedOrderAndFreeCaptain(): array
    {
        return [$this->makeFreeCaptain(), $this->strandedOrder()];
    }

    /**
     * Sign in as this captain. `X-App` is not optional — `client.type` answers
     * 403 without it, which is the same shape as «this captain may not do that»
     * and is not that at all.
     */
    private function asCaptain(Driver $driver): static
    {
        Sanctum::actingAs($driver->user);

        return $this->withHeader('X-App', 'app');
    }

    private function makeFreeCaptain(string $name = 'كابتن رامي'): Driver
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
            'active_order_id' => null,
            'current_lat' => 31.4388,
            'current_lng' => 31.5442,
            'location_updated_at' => now(),
        ]);
    }

    /** An order the kitchen has marked ready and nobody has taken. */
    private function strandedOrder(string $restaurantName = 'ابن البلد', float $lat = 31.3840, float $lng = 31.4840): Order
    {
        $restaurant = $this->makeRestaurant($restaurantName, $lat, $lng);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        $order->update(['status' => OrderStatus::SearchingDriver, 'driver_id' => null]);

        return $order->fresh();
    }
}
