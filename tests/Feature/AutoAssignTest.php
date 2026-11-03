<?php

namespace Tests\Feature;

use App\Actions\Admin\UnassignDriverAction;
use App\Actions\Restaurant\CreateDirectOrderAction;
use App\Actions\Restaurant\StartPreparingAction;
use App\Enums\AdminScopeType;
use App\Enums\ErrandStatus;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\AutoAssign;
use App\Support\DriverPresence;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 99 §3 — D-171. «يحدد أقرب كابتن لموقع المطعم … ثم يتم إسناد الطلب إليه مباشرة»,
 * «قبل نهاية وقت التحضير بـ10 دقائق».
 */
class AutoAssignTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    private Restaurant $home;

    private Restaurant $near;

    private Restaurant $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);
        Settings::set('ring.enabled', false);
        Settings::set('dispatch.strategy', 'auto_assign');
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'UTC'));

        $this->home = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
        $this->near = $this->makeRestaurant('سعد الدمياطي', 31.3930, 31.4840);
        $this->far = $this->makeRestaurant('ابن البلد', 31.4140, 31.4840);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ when

    public function test_less_than_the_lead_left_at_start_preparing_assigns_at_once(): void
    {
        $captain = $this->connected($this->captain('كابتن قريب', 31.3845, 31.4845));
        $order = $this->confirmed($this->single($this->home), prepMinutes: 7);

        $result = app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-7');

        $this->assertSame(OrderStatus::Preparing, $result->status, 'the place is answered «preparing»');
        $fresh = $order->fresh();
        $this->assertSame($captain->id, $fresh->driver_id);
        $this->assertSame(OrderStatus::DriverAssigned, $fresh->status);
        $this->assertSame('auto', $fresh->assigned_via);
        $this->assertNull($fresh->ready_at);
        $this->assertFalse($fresh->kitchen_ready);
    }

    public function test_a_long_countdown_waits_until_ten_minutes_before_it_is_due(): void
    {
        $captain = $this->connected($this->captain('كابتن قريب', 31.3845, 31.4845));
        $order = $this->confirmed($this->single($this->home), prepMinutes: 15);

        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-15');
        $this->assertNull($order->fresh()->driver_id, '15 minutes left, lead 10: not yet');

        $this->travel(4)->minutes();
        $this->keepConnected($captain);
        $this->tick();
        $this->assertNull($order->fresh()->driver_id, 'due at +5');

        $this->travel(1)->minutes();
        $this->keepConnected($captain);
        $this->tick();
        $this->assertSame($captain->id, $order->fresh()->driver_id);
        $this->assertSame(OrderStatus::DriverAssigned, $order->fresh()->status);
    }

    public function test_extending_prep_before_assignment_moves_the_due_time(): void
    {
        $captain = $this->connected($this->captain('كابتن قريب', 31.3845, 31.4845));
        $order = $this->preparing($this->single($this->home), 15);

        $this->travel(2)->minutes();
        $this->asPlace($this->home)->patchJson("/api/v1/restaurant/orders/{$order->id}/prep-time", ['extra_minutes' => 10])->assertOk();

        $this->travel(4)->minutes(); // +6: would have been due without the extension
        $this->keepConnected($captain);
        $this->tick();
        $this->assertNull($order->fresh()->driver_id);

        $this->travel(9)->minutes(); // +15 = 25 − 10
        $this->keepConnected($captain);
        $this->tick();
        $this->assertSame($captain->id, $order->fresh()->driver_id);
    }

    // ------------------------------------------------------------------ who

    public function test_the_nearest_captain_to_the_place_wins(): void
    {
        $farther = $this->connected($this->captain('كابتن بعيد', 31.3930, 31.4840));
        $nearest = $this->connected($this->captain('كابتن قريب', 31.3842, 31.4842));

        $order = $this->ready($this->single($this->home));

        $this->assertSame($nearest->id, $order->fresh()->driver_id);
        $this->assertSame(0, $farther->driverAttempts()->count(), 'nobody else is offered anything');
        $this->assertSame(0, $order->driverAttempts()->where('result', 'sent')->count());
    }

    public function test_a_parked_captain_whose_phone_stopped_sending_fixes_is_still_eligible(): void
    {
        // Five minutes since his last fix — «stale» for the fleet map (90 s), fine here (600 s).
        $parked = $this->connected($this->captain('كابتن واقف', 31.3842, 31.4842, ['location_updated_at' => now()->subMinutes(5)]));

        $order = $this->ready($this->single($this->home));

        $this->assertSame($parked->id, $order->fresh()->driver_id);
    }

    public function test_a_captain_with_no_fix_or_a_fix_too_old_is_skipped_and_never_treated_as_zero_zero(): void
    {
        $this->connected($this->captain('كابتن بلا موقع', 31.3842, 31.4842, ['current_lat' => null, 'current_lng' => null, 'location_updated_at' => null]));
        $this->connected($this->captain('كابتن موقعه قديم', 31.3842, 31.4842, ['location_updated_at' => now()->subMinutes(11)]));
        $fallback = $this->connected($this->captain('كابتن بعيد', 31.3930, 31.4840));

        $order = $this->ready($this->single($this->home));

        $this->assertSame($fallback->id, $order->fresh()->driver_id);
    }

    public function test_a_captain_who_is_not_connected_is_skipped(): void
    {
        // Online, fresh fix, but the server has not heard from the phone in three minutes.
        $silent = $this->captain('كابتن صامت', 31.3842, 31.4842);
        DriverPresence::touch($silent);
        $this->travel(3)->minutes();
        $silent->update(['location_updated_at' => now()]);

        $heard = $this->connected($this->captain('كابتن متصل', 31.3930, 31.4840));

        $order = $this->ready($this->single($this->home));

        $this->assertSame($heard->id, $order->fresh()->driver_id);
    }

    public function test_a_captain_on_an_errand_is_skipped(): void
    {
        $busy = $this->connected($this->captain('كابتن في مشوار', 31.3842, 31.4842));
        $this->errandFor($busy);
        $free = $this->connected($this->captain('كابتن فاضي', 31.3930, 31.4840));

        $order = $this->ready($this->single($this->home));

        $this->assertSame($free->id, $order->fresh()->driver_id);
    }

    public function test_the_trip_gates_hold_capacity_and_the_trip_radius(): void
    {
        $carrying = $this->connected($this->captain('كابتن معه طلب', 31.3842, 31.4842));
        $first = $this->ready($this->single($this->home));
        $this->assertSame($carrying->id, $first->fresh()->driver_id);

        // Three kilometres from the place he carries from: too far to join his trip.
        $free = $this->connected($this->captain('كابتن فاضي', 31.4300, 31.4840));
        $farOrder = $this->ready($this->single($this->far));
        $this->assertSame($free->id, $farOrder->fresh()->driver_id);

        // A kilometre away: it joins the trip of the nearest captain.
        $nearOrder = $this->ready($this->single($this->near));
        $this->assertSame($carrying->id, $nearOrder->fresh()->driver_id);

        // Room for one: he is skipped.
        Settings::set('dispatch.max_orders_per_trip', 2);
        $third = $this->ready($this->single($this->home));
        $this->assertNotSame($carrying->id, (int) $third->fresh()->driver_id);
    }

    public function test_the_baskets_holder_comes_first(): void
    {
        [$a, $b] = $this->basket([$this->home, $this->near]);
        $holder = $this->connected($this->captain('كابتن السلة', 31.3930, 31.4840));

        $a = $this->ready($a);
        $this->assertSame($holder->id, $a->fresh()->driver_id);

        // A nearer captain appears; the sibling still goes to the basket's captain.
        $this->connected($this->captain('كابتن أقرب', 31.3842, 31.4842));
        $b = $this->preparing($b, 5);
        $this->tick();
        $this->assertNull($b->fresh()->driver_id, 'still cooking: held on the holder\'s trip, not given to anyone');

        $this->ready($b);
        $this->assertSame($holder->id, $b->fresh()->driver_id);
        $this->assertSame('basket', $b->fresh()->assigned_via);
    }

    public function test_two_orders_racing_for_one_captain_with_room_for_one_get_one(): void
    {
        Settings::set('dispatch.batching_enabled', false);
        $only = $this->connected($this->captain('الكابتن الوحيد', 31.3842, 31.4842));

        $first = $this->preparing($this->single($this->home), 5);
        $second = $this->preparing($this->single($this->home), 5);

        // The second order's candidate list is read before the first is assigned — stale.
        $staleCandidates = app(AutoAssign::class)->candidates($second->fresh());
        $this->assertSame([$only->id], $staleCandidates->pluck('driver.id')->all());

        $this->assertTrue(app(AutoAssign::class)->attempt($first->fresh(), $only));
        $this->assertFalse(app(AutoAssign::class)->attempt($second->fresh(), $staleCandidates->first()['driver']), 're-checked under the lock: his trip is full');

        $this->tick();
        $this->assertSame($only->id, $first->fresh()->driver_id);
        $this->assertNull($second->fresh()->driver_id);
    }

    public function test_one_order_cannot_get_two_captains_and_a_second_tick_changes_nothing(): void
    {
        $a = $this->connected($this->captain('كابتن أ', 31.3842, 31.4842));
        $b = $this->connected($this->captain('كابتن ب', 31.3843, 31.4843));
        $order = $this->preparing($this->single($this->home), 5);

        $this->assertTrue(app(AutoAssign::class)->attempt($order->fresh(), $a));
        $this->assertFalse(app(AutoAssign::class)->attempt($order->fresh(), $b));

        $history = $order->statusHistory()->count();
        $attempts = $order->driverAttempts()->count();
        $this->tick();
        $this->tick();

        $this->assertSame($a->id, $order->fresh()->driver_id);
        $this->assertSame($history, $order->statusHistory()->count());
        $this->assertSame($attempts, $order->driverAttempts()->count());
    }

    public function test_a_captain_the_office_took_off_the_order_is_not_given_it_back(): void
    {
        $first = $this->connected($this->captain('الكابتن الأول', 31.3842, 31.4842));
        $second = $this->connected($this->captain('الكابتن الثاني', 31.3930, 31.4840));
        $order = $this->ready($this->single($this->home));
        $this->assertSame($first->id, $order->fresh()->driver_id);

        app(UnassignDriverAction::class)->execute($this->admin(), $order->fresh(), 'driver_unresponsive', null, true);

        $this->assertSame($second->id, $order->fresh()->driver_id);
    }

    public function test_the_captain_who_cancels_the_trip_is_replaced_by_the_next_nearest(): void
    {
        $nearest = $this->connected($this->captain('الأقرب', 31.3842, 31.4842));
        $next = $this->connected($this->captain('التالي', 31.3880, 31.4840));
        $order = $this->preparing($this->single($this->home), 5);
        $this->tick();
        $this->assertSame($nearest->id, $order->fresh()->driver_id);

        $this->asCaptain($nearest)->postJson("/api/v1/driver/orders/{$order->id}/cancel", ['reason_code' => 'vehicle_issue'])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame($next->id, $fresh->driver_id);
        $this->assertSame(OrderStatus::DriverAssigned, $fresh->status);
        $this->assertNull($fresh->ready_at, 'still cooking: it went back to the kitchen state, not to «ready»');
        $this->assertTrue($order->statusHistory()->where('to_status', 'preparing')->exists());
    }

    public function test_nobody_found_is_retried_every_tick_and_escalated_once(): void
    {
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        $admin = $this->admin();
        $order = $this->preparing($this->single($this->home), 5);

        $this->tick();
        $fresh = $order->fresh();
        $this->assertNull($fresh->driver_id);
        $this->assertSame(OrderStatus::Preparing, $fresh->status, 'the order stays as it is');
        $this->assertNotNull($fresh->dispatch_started_at);
        $this->assertSame(0, Notification::query()->where('type', 'order.unclaimed_admin')->count());

        $this->travel(3)->minutes();
        $this->tick();
        $this->tick();
        $this->assertSame(1, Notification::query()->where('user_id', $admin->id)->where('type', 'order.unclaimed_admin')->count());

        $this->actingAsAdmin($admin)->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.dispatch.strategy', 'auto_assign')
            ->assertJsonPath('data.dispatch.no_candidate_since', $fresh->dispatch_started_at->toJSON())
            ->assertJsonPath('data.dispatch.assigned_via', null);

        $captain = $this->connected($this->captain('كابتن وصل', 31.3842, 31.4842));
        $this->tick();
        $this->assertSame($captain->id, $order->fresh()->driver_id);
        $this->actingAsAdmin($admin)->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertJsonPath('data.dispatch.assigned_via', 'auto')
            ->assertJsonPath('data.dispatch.no_candidate_since', null);
    }

    public function test_food_ready_with_nobody_free_waits_for_a_driver_and_is_assigned_when_one_comes(): void
    {
        $order = $this->ready($this->single($this->home));
        $this->assertSame(OrderStatus::WaitingForDriver, $order->fresh()->status);
        $this->assertSame(0, $order->driverAttempts()->count(), 'no offer to anybody');

        // A captain coming on shift: the pass runs at once, no offer is created.
        $captain = $this->captain('كابتن جديد', 31.3842, 31.4842, ['is_online' => false]);
        $this->asCaptain($captain)->postJson('/api/v1/driver/status', ['is_online' => true])->assertOk();

        $this->assertSame($captain->id, $order->fresh()->driver_id);
        $this->assertSame(0, $order->driverAttempts()->where('result', 'sent')->count());
    }

    public function test_a_direct_order_made_ready_now_is_auto_assigned(): void
    {
        $captain = $this->connected($this->captain('كابتن قريب', 31.3842, 31.4842));
        $this->home->update(['direct_orders_enabled' => true]);

        $direct = app(CreateDirectOrderAction::class)->execute($this->home->owner, $this->home->fresh(), [
            'guest_customer_name' => 'محمود',
            'guest_customer_phone' => '01012345678',
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'food_payment' => 'paid_at_restaurant',
            'restaurant_order_ref' => 'D-1',
            'ready_now' => true,
        ], 'direct-'.uniqid());

        $this->assertSame($captain->id, $direct->fresh()->driver_id);
        $this->assertSame('auto', $direct->fresh()->assigned_via);
        $this->assertTrue($direct->fresh()->kitchen_ready);
    }

    public function test_the_captain_hears_order_assigned_with_via_auto(): void
    {
        $captain = $this->connected($this->captain('كابتن قريب', 31.3842, 31.4842));
        $order = $this->ready($this->single($this->home));

        $row = Notification::query()->where('user_id', $captain->user_id)->where('order_id', $order->id)->where('type', 'order.assigned')->sole();
        $this->assertStringContainsString('كنج بروست', $row->payload['body_ar']);
        $this->assertSame(0, Notification::query()->where('user_id', $captain->user_id)->where('type', 'offer.new')->count());
    }

    public function test_the_admin_summary_counts_connected_captains_and_orders_awaiting_a_captain(): void
    {
        $admin = $this->admin();
        $this->connected($this->captain('متصل', 31.3842, 31.4842));
        $this->captain('مفتوح بلا اتصال', 31.3842, 31.4842);

        $this->preparing($this->single($this->near), 30); // due in 20 minutes
        $this->preparing($this->single($this->far), 5);   // due now, nothing has run yet

        $summary = $this->actingAsAdmin($admin)->getJson('/api/v1/admin/dashboard/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['connected_drivers']);
        $this->assertSame(2, $summary['online_drivers']);
        $this->assertSame(1, $summary['awaiting_assignment'], 'the due one only');
    }

    // ------------------------------------------------------------------ broadcast

    public function test_broadcast_mode_is_unchanged(): void
    {
        Settings::set('dispatch.strategy', 'broadcast');
        $captain = $this->connected($this->captain('كابتن قريب', 31.3842, 31.4842));

        $order = $this->confirmed($this->single($this->home), prepMinutes: 5);
        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-5');
        $this->tick();
        $this->assertNull($order->fresh()->driver_id, 'no captain before the food is ready');

        $this->ready($order->fresh());
        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame(OrderStatus::SearchingDriver, $order->fresh()->status);
        $this->assertSame([$order->id], $this->openOffers($captain));

        $this->acceptAs($captain, $order)->assertOk();
        $this->assertSame('accept', $order->fresh()->assigned_via);
    }

    // ------------------------------------------------------------------ helpers

    private function single(Restaurant $place): Order
    {
        return $this->basket([$place])->first();
    }

    private function confirmed(Order $order, int $prepMinutes): Order
    {
        $order->update(['status' => OrderStatus::Confirmed, 'prep_minutes' => $prepMinutes, 'confirmed_at' => now()]);

        return $order->fresh();
    }

    private function connected(Driver $captain): Driver
    {
        DriverPresence::touch($captain);

        return $captain;
    }

    /** Time has moved on: the phone has kept polling and pinging meanwhile. */
    private function keepConnected(Driver ...$captains): void
    {
        foreach ($captains as $captain) {
            $captain->update(['location_updated_at' => now()]);
            DriverPresence::touch($captain->fresh());
        }
    }

    private function tick(): void
    {
        $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();
    }

    private function asPlace(Restaurant $place): static
    {
        Sanctum::actingAs($place->owner);

        return $this->withHeader('X-App', 'restaurant');
    }

    private function actingAsAdmin(User $admin): static
    {
        Sanctum::actingAs($admin);

        return $this->withHeader('X-App', 'admin');
    }

    private ?User $adminUser = null;

    private function admin(): User
    {
        if ($this->adminUser !== null) {
            return $this->adminUser;
        }

        if (! \App\Models\Role::query()->where('name', 'super_admin')->exists()) {
            $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        }

        $admin = User::query()->create(['name' => 'Admin', 'phone' => '01000000099', 'type' => 'admin', 'is_active' => true]);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $this->adminUser = $admin;
    }

    private function errandFor(Driver $captain): ErrandRequest
    {
        return ErrandRequest::query()->create([
            'reference' => 'ER-'.random_int(1000, 9999),
            'user_id' => $this->makeCustomer()->id,
            'driver_id' => $captain->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => ErrandStatus::Assigned,
            'pickup_address_text' => 'صيدلية جمصة',
            'pickup_lat' => 31.384,
            'pickup_lng' => 31.484,
            'dropoff_address_text' => 'شارع البحر',
            'dropoff_lat' => 31.386,
            'dropoff_lng' => 31.486,
            'details' => 'دواء',
            'distance_km' => 2.1,
            'offered_fee' => 40,
            'assigned_at' => now(),
        ]);
    }
}
