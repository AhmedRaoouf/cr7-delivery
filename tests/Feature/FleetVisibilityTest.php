<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Support\DispatchService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One captain, three admin screens, three different answers — reported from production
 * on 2026-09-05 with `ADM-19` showing «متصل ومتفرغ», `ADM-56` showing zero captains
 * online, and `ADM-07` refusing to list anyone at all.
 *
 * Four separate defects produced that, and none of them is visible from the screen:
 *
 *  1. `GET /admin/dashboard/live-drivers` filtered on `is_online` and then did not
 *     select the column, so the fleet board read `undefined` and scored every captain
 *     `offline`.
 *  2. `eligibleDrivers()` dereferenced a null `location_updated_at`, which is exactly
 *     the row `include_offline` exists to include — a 500 that `ADM-07` renders as its
 *     empty state.
 *  3. The radius filter dropped **live** captains, so the only captain on the road
 *     disappeared while four captains who had not pinged in a week stayed.
 *  4. Dispatch read `driver.location_stale_seconds` (60) while D-56, both dashboards
 *     and `Driver::isLive()` use 90.
 */
class FleetVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);
        // `client.type` — doc 08 §1. Without the header the request is refused before
        // it reaches the controller, with «هذا الحساب لا يمكنه الدخول من هذه الواجهة».
        $this->withHeader('X-App', 'admin');

        return $admin;
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

    /** A real order through `CreateOrderAction`, moved to the state dispatch starts in. */
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

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        $order->update(['status' => OrderStatus::Ready]);

        return $order->fresh();
    }

    public function test_live_drivers_returns_the_online_flag_the_fleet_board_reads(): void
    {
        $this->admin();
        $this->makeDriver('كابتن يوسف');

        $response = $this->getJson('/api/v1/admin/dashboard/live-drivers')->assertOk();

        // Not `assertJsonPath(... true)` alone: the defect was the key being *absent*,
        // and a missing key and a false one are the same `?? false` on the board.
        $this->assertArrayHasKey('is_online', $response->json('data.0'));
        $this->assertTrue($response->json('data.0.is_online'));
    }

    public function test_eligible_drivers_with_include_offline_survives_a_captain_who_never_pinged(): void
    {
        $this->admin();
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);

        // Online, and no location row — the state that answered 500.
        $this->makeDriver('محمود سعيد', ['location_updated_at' => null, 'current_lat' => null, 'current_lng' => null]);

        $this->getJson("/api/v1/admin/orders/{$order->id}/eligible-drivers?include_offline=1")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'محمود سعيد')
            ->assertJsonPath('data.0.state', 'stale')
            ->assertJsonPath('data.0.last_seen_at', null);
    }

    public function test_a_live_captain_outside_the_radius_is_still_offered(): void
    {
        $this->admin();
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);

        // ~55 km north of Gamasa: outside any dispatch radius, and assignable anyway.
        $this->makeDriver('كابتن بعيد', ['current_lat' => 31.8841, 'current_lng' => 31.4841]);
        $this->makeDriver('كابتن قديم', ['location_updated_at' => now()->subDays(6)]);

        $response = $this->getJson("/api/v1/admin/orders/{$order->id}/eligible-drivers")->assertOk();

        // Live first even though it is the further of the two.
        $response->assertJsonPath('data.0.name', 'كابتن بعيد')
            ->assertJsonPath('data.0.state', 'live')
            ->assertJsonPath('data.1.name', 'كابتن قديم')
            ->assertJsonPath('data.1.state', 'stale');
    }

    public function test_a_stale_captain_outside_the_radius_is_not_offered(): void
    {
        $this->admin();
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);

        $this->makeDriver('كابتن بعيد وقديم', [
            'current_lat' => 31.8841,
            'current_lng' => 31.4841,
            'location_updated_at' => now()->subDays(6),
        ]);

        $this->getJson("/api/v1/admin/orders/{$order->id}/eligible-drivers")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_liveness_uses_the_ninety_second_threshold_of_d56(): void
    {
        $this->admin();
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);

        // 70 seconds: live under D-56's 90, stale under the 60 the code was reading.
        $this->makeDriver('كابتن يوسف', ['location_updated_at' => now()->subSeconds(70)]);

        $this->getJson("/api/v1/admin/orders/{$order->id}/eligible-drivers")
            ->assertOk()
            ->assertJsonPath('data.0.state', 'live');
    }

    public function test_an_operator_edit_of_the_older_setting_key_still_applies(): void
    {
        $this->admin();
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);
        $this->makeDriver('كابتن يوسف', ['location_updated_at' => now()->subSeconds(70)]);

        Setting::query()->create(['key' => 'dispatch.location_stale_seconds', 'value' => 30, 'group' => 'dispatch']);

        $this->getJson("/api/v1/admin/orders/{$order->id}/eligible-drivers")
            ->assertOk()
            ->assertJsonPath('data.0.state', 'stale');
    }

    public function test_a_captain_with_no_assigned_zone_is_dispatched_to(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);
        $driver = $this->makeDriver('كابتن بلا نطاق');

        $this->assertCount(0, $driver->assignedZones()->get());

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
        ]);
    }

    public function test_signing_out_takes_the_captain_offline(): void
    {
        $driver = $this->makeDriver('كابتن يوسف', ['online_since' => now()]);

        Sanctum::actingAs($driver->user);
        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $driver->refresh();

        $this->assertFalse($driver->is_online);
        $this->assertNull($driver->online_since);
        // Still theirs: signing out does not unassign a delivery already in hand.
        $this->assertTrue($driver->is_available);
    }

    public function test_signing_out_everywhere_takes_the_captain_offline_too(): void
    {
        $driver = $this->makeDriver('كابتن يوسف', ['online_since' => now()]);

        Sanctum::actingAs($driver->user);
        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk();

        $this->assertFalse($driver->refresh()->is_online);
    }

    /**
     * 🔴 The reverse of what this file asserted until 2026-09-12, and the reason
     * «الطلب ما بيروحش للكباتن، لازم أعمل إسناد من الأدمن».
     *
     * `dispatch.allow_neighbour_zones` has been seeded `true` since day one and
     * was read by nothing: three queries hard-filtered on `service_zone_driver`,
     * so a captain assigned to one of D-70's sixteen priced areas was invisible
     * to an order in any of the other fifteen — at `ready`, at the two-minute
     * rebroadcast, and when they came on shift. `ReassignDriverAction` checks no
     * zone at all, which is why the office could hand over the very order the
     * dispatcher had refused to offer.
     */
    public function test_a_captain_assigned_to_another_zone_is_still_offered_the_order(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);
        $driver = $this->inAnotherZone('كابتن نطاق آخر');

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
        ]);
    }

    /** The same captain, on the path that runs every two minutes. */
    public function test_the_rebroadcast_reaches_a_captain_from_another_zone(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::WaitingForDriver]);
        $driver = $this->inAnotherZone('كابتن نطاق آخر');

        $this->assertSame(1, app(DispatchService::class)->rebroadcast($order->fresh()));
        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
        ]);
    }

    /** And on the third: a captain who comes on shift is handed the backlog. */
    public function test_the_backlog_reaches_a_captain_from_another_zone(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::WaitingForDriver]);
        $driver = $this->inAnotherZone('كابتن نطاق آخر');

        $this->assertSame(1, app(DispatchService::class)->offerBacklogTo($driver->fresh()));
    }

    /** The operator can still put the gate back, with one row. */
    public function test_the_zone_gate_returns_when_neighbour_zones_are_refused(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'dispatch.allow_neighbour_zones'],
            ['value' => '0', 'group' => 'dispatch'],
        );

        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant);
        $driver = $this->inAnotherZone('كابتن نطاق آخر');

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->assertDatabaseMissing('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
        ]);
    }

    private function inAnotherZone(string $name): Driver
    {
        $other = $this->city->serviceZones()->create([
            'name_ar' => 'نطاق آخر', 'name_en' => 'Other', 'center_lat' => 31.39, 'center_lng' => 31.49,
            'radius_km' => 3, 'delivery_fee' => 20, 'min_order_value' => 0, 'avg_delivery_minutes' => 30, 'is_active' => true,
        ]);

        $driver = $this->makeDriver($name);
        $driver->assignedZones()->sync([$other->id]);

        return $driver;
    }
}
