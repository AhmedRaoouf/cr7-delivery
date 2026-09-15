<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Driver;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\SendsPushesAfterTheResponse;
use Tests\TestCase;

/**
 * doc 63 — three defects older than the trip that the trip made visible.
 *
 * - The customer's map called the captain carrying their order «stale», always: it read
 *   `Driver::isLive()`, which also requires `is_available` — «carries nothing» (D-117).
 * - A captain the office took an order from was told nothing whenever the status moved
 *   with the captain: `driverChanged()` only speaks when `driver_id` moves alone.
 * - A requeued order skipped every captain ever asked about it until the two-minute
 *   rebroadcast, because the requeue ran as a *first* round.
 */
class TripFollowUpsTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;
    use SendsPushesAfterTheResponse;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);
        Settings::set('ring.enabled', false);
        $this->configureFirebase();
        $this->fakeFcm();

        $this->place = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
    }

    public function test_the_customers_map_calls_a_captain_carrying_their_order_live(): void
    {
        [$order] = $this->basket([$this->place]);
        $captain = $this->captain('كابتن الأول');

        $this->ready($order);
        $this->acceptAs($captain, $order)->assertOk();
        $this->assertFalse((bool) $captain->fresh()->is_available, 'carrying an order is «not available»');

        Sanctum::actingAs($order->orderGroup->user);
        $driver = $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar'])
            ->getJson("/api/v1/orders/{$order->order_group_id}/tracking")
            ->assertOk()
            ->json('data.sub_orders.0.driver');

        $this->assertSame($captain->id, $driver['id']);
        $this->assertFalse($driver['is_location_stale'], 'a fix taken seconds ago is live');

        $captain->forceFill(['location_updated_at' => now()->subMinutes(5)])->save();

        $driver = $this->getJson("/api/v1/orders/{$order->order_group_id}/tracking")->json('data.sub_orders.0.driver');
        $this->assertTrue($driver['is_location_stale'], 'a five-minute-old fix is stale');
    }

    public function test_a_captain_the_office_unassigns_is_told(): void
    {
        [$order] = $this->basket([$this->place]);
        $captain = $this->phoneFor($this->captain('كابتن الأول'));

        $this->ready($order);
        $this->acceptAs($captain, $order)->assertOk();

        $this->pretendThisIsAWebRequest();
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/unassign-driver", ['reason_code' => 'driver_unresponsive'])
            ->assertOk();

        $pushes = $this->pushesOfType('captain-'.$captain->id, 'order.unassigned');
        $this->assertCount(1, $pushes);
        $this->assertSame((string) $order->id, $pushes[0]['data']['order_id']);
        $this->assertSame('driver_unresponsive', $pushes[0]['data']['reason_code'], 'DRV-35 says why');
    }

    public function test_the_basket_offer_says_what_each_stop_costs_in_cash(): void
    {
        $second = $this->makeRestaurant('سعد الدمياطي', 31.3930, 31.4840);
        [$a, $b] = $this->basket([$this->place, $second]);
        $captain = $this->captain('كابتن الأول');

        $this->ready($a);
        $this->ready($b);

        $offers = $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk()->json('data');
        $this->assertCount(1, $offers, 'one card per basket');

        $basket = $offers[0]['basket'];
        foreach ($basket['orders'] as $stop) {
            $this->assertArrayHasKey('paid_at_pickup', $stop);
            $this->assertArrayHasKey('cash_to_collect', $stop);
        }

        $this->assertEqualsWithDelta(
            array_sum(array_column($basket['orders'], 'paid_at_pickup')),
            $basket['pay_places_total'],
            0.001,
        );
        $this->assertEqualsWithDelta(
            array_sum(array_column($basket['orders'], 'cash_to_collect')),
            $basket['cash_to_collect_total'],
            0.001,
        );
        $this->assertGreaterThan(0, $basket['cash_to_collect_total'], 'a cash basket collects something at the door');
    }

    public function test_a_captain_reassigned_away_after_reaching_the_place_is_told_once(): void
    {
        [$order] = $this->basket([$this->place]);
        $first = $this->phoneFor($this->captain('كابتن الأول'));
        $second = $this->captain('كابتن الثاني');

        $this->ready($order);
        $this->acceptAs($first, $order)->assertOk();
        $this->asCaptain($first)->postJson("/api/v1/driver/orders/{$order->id}/arrived-restaurant")->assertOk();

        $this->pretendThisIsAWebRequest();
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", ['driver_id' => $second->id, 'reason_code' => 'nearest', 'mode' => 'force'])
            ->assertOk();

        $this->assertSame($second->id, (int) $order->fresh()->driver_id);
        $this->assertCount(1, $this->pushesOfType('captain-'.$first->id, 'order.reassigned'));
    }

    public function test_a_reassignment_that_leaves_the_status_is_still_told_once_not_twice(): void
    {
        [$order] = $this->basket([$this->place]);
        $first = $this->phoneFor($this->captain('كابتن الأول'));
        $second = $this->captain('كابتن الثاني');

        $this->ready($order);
        $this->acceptAs($first, $order)->assertOk();

        $this->pretendThisIsAWebRequest();
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", ['driver_id' => $second->id, 'reason_code' => 'nearest', 'mode' => 'force'])
            ->assertOk();

        $this->assertCount(1, $this->pushesOfType('captain-'.$first->id, 'order.reassigned'));
    }

    public function test_an_order_a_captain_steps_off_is_offered_at_once_to_the_captains_asked_before(): void
    {
        [$order] = $this->basket([$this->place]);
        $first = $this->captain('كابتن الأول');
        $second = $this->captain('كابتن الثاني', 31.3850, 31.4850);

        $this->ready($order);
        $this->assertContains($order->id, $this->openOffers($second));

        $this->acceptAs($first, $order)->assertOk();
        $this->assertNotContains($order->id, $this->openOffers($second), 'the accept closed the other offer');

        $this->asCaptain($first)
            ->postJson("/api/v1/driver/orders/{$order->id}/cancel", ['reason_code' => 'vehicle_issue'])
            ->assertOk();

        $this->assertContains($order->id, $this->openOffers($second), 'asked again now, not in two minutes');
        $this->assertNotContains($order->id, $this->openOffers($first), 'never handed back to the captain who stepped off');
    }

    public function test_an_order_the_office_requeues_is_offered_at_once_but_never_back_to_the_captain_it_was_taken_from(): void
    {
        [$order] = $this->basket([$this->place]);
        $first = $this->captain('كابتن الأول');
        $second = $this->captain('كابتن الثاني', 31.3850, 31.4850);

        $this->ready($order);
        $this->acceptAs($first, $order)->assertOk();

        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/unassign-driver", ['reason_code' => 'driver_unresponsive', 'requeue' => true])
            ->assertOk();

        $this->assertContains($order->id, $this->openOffers($second));
        $this->assertNotContains($order->id, $this->openOffers($first));
    }

    private function phoneFor(Driver $captain): Driver
    {
        $captain->user->forceFill(['locale' => 'ar'])->save();
        $captain->user->deviceTokens()->create([
            'token' => 'captain-'.$captain->id,
            'platform' => 'android',
            'app' => 'driver',
            'device_id' => 'captain-device-'.$captain->id,
        ]);

        return $captain;
    }

    private function asAdmin(): static
    {
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);

        $admin = User::query()->create(['name' => 'Admin', 'phone' => '01000000099', 'type' => 'admin', 'is_active' => true]);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin', 'Accept-Language' => 'ar']);
    }
}
