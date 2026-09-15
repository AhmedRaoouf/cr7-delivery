<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Driver\DeliverOrderAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Support\DispatchService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «قبلت طلبين ورا بعض، وودّاني على صفحة سودة» — reported 2026-09-05. The second accept
 * overwrote `active_order_id`, the first order kept `driver_id` and became unreachable,
 * and a customer's food no longer had a captain while nothing anywhere said so.
 *
 * The fix then was «one order per captain»: `already_on_a_trip`. doc 63 (D-116) makes a
 * second order the point — a trip carries up to three — so the rule this file guards is
 * restated rather than dropped: **a second accept never drops the first order.** Either
 * it joins the trip and both are the captain's, reachable and delivered one by one; or it
 * does not fit and it is refused in words, and the first keeps its captain either way.
 */
class NoOrderIsDroppedBySecondAcceptTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT = [31.3892245, 31.4720504];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();

        foreach ([
            'dispatch.batch_size' => 3,
            'dispatch.radius_km' => 5,
            'dispatch.offer_ttl_seconds' => 30,
            'dispatch.max_rounds' => 3,
            'dispatch.location_stale_seconds' => 90,
        ] as $key => $value) {
            Setting::query()->create(['key' => $key, 'value' => $value, 'group' => 'test']);
        }
    }

    public function test_a_second_accept_joins_the_trip_and_the_first_order_keeps_its_captain(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $first = $this->makeOrder($restaurant);
        $second = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        // Both offers reach the captain before either is answered.
        $dispatch = app(DispatchService::class);
        $dispatch->dispatchRound($first, 1);
        $dispatch->dispatchRound($second, 1);

        $this->accept($driver, $first)->assertOk();
        $this->accept($driver, $second)->assertOk();

        foreach ([$first, $second] as $order) {
            $order->refresh();
            $this->assertSame($driver->id, $order->driver_id, 'the captain must keep every order they took');
            $this->assertSame(OrderStatus::DriverAssigned, $order->status);
        }

        $driver->refresh();
        $this->assertFalse($driver->is_available);
        $this->assertContains($driver->active_order_id, [$first->id, $second->id]);

        // Both are reachable: the whole trip, and the focus the 1.0.3 app reads.
        $trip = $this->asCaptain($driver)->getJson('/api/v1/driver/trip')->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], collect($trip->json('data.orders'))->pluck('id')->all());
        $this->asCaptain($driver)->getJson('/api/v1/driver/orders/active')
            ->assertOk()
            ->assertJsonPath('data.id', $driver->active_order_id);
    }

    public function test_with_batching_off_the_offer_in_hand_is_closed_and_the_first_order_keeps_its_captain(): void
    {
        Settings::set('dispatch.batching_enabled', false);

        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $first = $this->makeOrder($restaurant);
        $second = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        $dispatch = app(DispatchService::class);
        $dispatch->dispatchRound($first, 1);
        $dispatch->dispatchRound($second, 1);

        $this->accept($driver, $first)->assertOk();

        // Re-checked the moment the first was taken: it no longer fits, so it is closed
        // rather than left as a «قبول» that cannot work (doc 63 §2.2).
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $second->id, 'driver_id' => $driver->id, 'result' => 'superseded',
        ]);
        $this->accept($driver, $second)->assertStatus(403)->assertJsonPath('error', 'not_eligible');

        $this->assertSame($first->id, $driver->fresh()->active_order_id, 'the captain must keep the order they took');

        // And the order they did not get must still be findable by someone else.
        $second->refresh();
        $this->assertNull($second->driver_id);
        $this->assertNotSame(OrderStatus::DriverAssigned, $second->status);
    }

    /**
     * The offer was fine when it was sent and stopped fitting before the press — here the
     * operator switched batching off in between. The answer is a sentence, not a generic
     * error, and nothing the captain already carries moves.
     */
    public function test_an_offer_that_stopped_fitting_before_the_press_is_refused_in_words(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $first = $this->makeOrder($restaurant);
        $second = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        $dispatch = app(DispatchService::class);
        $dispatch->dispatchRound($first, 1);
        $dispatch->dispatchRound($second, 1);

        $this->accept($driver, $first)->assertOk();

        Settings::set('dispatch.batching_enabled', false);

        $this->withHeader('Accept-Language', 'ar');

        $this->accept($driver, $second)
            ->assertStatus(409)
            ->assertJsonPath('error', 'trip_full')
            ->assertJsonPath('message', 'رحلتك مكتملة — طلب واحد كحدٍّ أقصى')
            ->assertJsonPath('meta.trip_count', 1)
            ->assertJsonPath('meta.trip_max', 1);

        $this->assertSame($driver->id, $first->fresh()->driver_id);
        $this->assertNull($second->fresh()->driver_id);
    }

    public function test_the_captain_can_take_a_new_order_once_the_first_is_delivered(): void
    {
        Settings::set('dispatch.batching_enabled', false);

        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $first = $this->makeOrder($restaurant);
        $second = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        $dispatch = app(DispatchService::class);
        $dispatch->dispatchRound($first, 1);
        $dispatch->dispatchRound($second, 1);

        $this->accept($driver, $first)->assertOk();

        // The guard is «the trip is full», not «has ever carried one». The real path:
        // the delivery frees the room and the backlog is offered in the same breath.
        DeliverOrderAction::complete($driver->fresh(), $first->fresh(), null, OrderStatusActorType::Driver, $driver->user_id);

        $driver->refresh();
        $this->assertNull($driver->active_order_id);
        $this->assertTrue($driver->is_available);

        $dispatch->offerBacklogTo($driver);

        $this->accept($driver, $second)->assertOk();
        $this->assertSame($second->id, $driver->fresh()->active_order_id);
    }

    public function test_accepting_the_same_order_twice_is_not_treated_as_a_second_trip(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->accept($driver, $order)->assertOk();

        // A double tap, or a retry after a dropped response. It must read as «already
        // assigned» — and never as a second order on the trip.
        $response = $this->accept($driver, $order)->assertStatus(409);
        $this->assertSame('already_assigned', $response->json('error'));
        $this->assertSame(1, Order::query()->where('driver_id', $driver->id)->count());
    }

    // --------------------------------------------------------------- helpers

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

    private function makeDriver(): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => 'كابتن رامي']);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => self::RESTAURANT[0],
            'current_lng' => self::RESTAURANT[1],
            'location_updated_at' => now(),
        ]);
    }

    private function asCaptain(Driver $driver): static
    {
        Sanctum::actingAs($driver->user);

        return $this->withHeaders(['X-App' => 'app']);
    }

    private function accept(Driver $driver, Order $order)
    {
        return $this->asCaptain($driver)->postJson("/api/v1/driver/orders/{$order->id}/accept");
    }
}
