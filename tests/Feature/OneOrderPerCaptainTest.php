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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «قبلت طلبين ورا بعض، وودّاني على صفحة سودة» — reported 2026-09-05, and the black
 * screen was the smallest part of it.
 *
 * `DispatchService` excludes a busy captain from the pool, and `ReassignDriverAction`
 * refuses to hand an order to one. `AcceptOfferAction` checked neither: an offer already
 * *in the captain's hand* when they accepted the previous one stayed answerable, and the
 * second accept overwrote `active_order_id`.
 *
 * The first order kept `driver_id` and its `driver_assigned` status and became
 * unreachable. `/driver/orders/active` reads `active_order_id`, so the captain could not
 * open it, could not deliver it, and nothing anywhere said a delivery had been dropped.
 * On the deployment that is a customer waiting for food that no longer has a captain.
 */
class OneOrderPerCaptainTest extends TestCase
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

    private function accept(Driver $driver, Order $order)
    {
        Sanctum::actingAs($driver->user);

        return $this->withHeaders(['X-App' => 'app'])
            ->postJson("/api/v1/driver/orders/{$order->id}/accept");
    }

    public function test_a_second_accept_is_refused_and_the_first_order_keeps_its_captain(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $first = $this->makeOrder($restaurant);
        $second = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        // Both offers reach the captain before either is answered — which is exactly
        // what a stranded order being re-offered every 90 s produces.
        $dispatch = app(DispatchService::class);
        $dispatch->dispatchRound($first, 1);
        $dispatch->dispatchRound($second, 1);

        $this->accept($driver, $first)->assertOk();

        $response = $this->accept($driver, $second)->assertStatus(409);
        $this->assertSame('already_on_a_trip', $response->json('error'));
        $this->assertSame($first->id, $response->json('meta.active_order_id'));

        $driver->refresh();
        $this->assertSame($first->id, $driver->active_order_id, 'the captain must keep the order they took');
        $this->assertFalse((bool) $driver->is_available);

        // And the order they did not get must still be findable by someone else.
        $second->refresh();
        $this->assertNull($second->driver_id);
        $this->assertNotSame(OrderStatus::DriverAssigned, $second->status);
    }

    public function test_the_captain_can_take_a_new_order_once_the_first_is_delivered(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $first = $this->makeOrder($restaurant);
        $second = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        $dispatch = app(DispatchService::class);
        $dispatch->dispatchRound($first, 1);
        $dispatch->dispatchRound($second, 1);

        $this->accept($driver, $first)->assertOk();

        // The guard is «carrying an order», not «has ever carried one».
        //
        // `refresh()` first, and it matters: `accept()` set `is_available` to
        // false on the row, while this local instance still remembers the true
        // it was created with. Assigning true to an attribute the model already
        // believes is true leaves it clean, so `save()` writes nothing and the
        // captain stays unavailable in the database while reading available in
        // the test. It was invisible until `offerBacklogTo` began reading that
        // column.
        $driver->refresh();
        $driver->update(['active_order_id' => null, 'is_available' => true]);

        // Accepting `first` now closes this captain's open offer on `second`
        // too, which it did not before 2026-09-12. That is deliberate: a captain
        // can be handed the whole backlog at once
        // ({@see DispatchService::offerBacklogTo}), and an offer left open while
        // they were mid-trip draws a «قبول» that can only answer
        // `already_on_a_trip`.
        //
        // So the offer is re-issued here, which is exactly what the real path
        // does — `DeliverOrderAction` frees the captain and immediately offers
        // them the backlog. The two lines above are that path's shortcut, and
        // the shortcut had stopped modelling it.
        $dispatch->offerBacklogTo($driver->fresh());

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
        // assigned», never as «you are on another trip» — the captain is on this one.
        $response = $this->accept($driver, $order)->assertStatus(409);
        $this->assertSame('already_assigned', $response->json('error'));
    }
}
