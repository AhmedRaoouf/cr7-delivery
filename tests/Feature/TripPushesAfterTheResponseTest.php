<?php

namespace Tests\Feature;

use App\Actions\Restaurant\MarkOrderReadyAction;
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
 * doc 63's pushes, over the path they take in production.
 *
 * Each of these leaves `afterResponse()`, which over HTTP serialises the push closure's
 * source and parses it back — the trip that silenced every place push for forty minutes on
 * 2026-09-14 while ‪699‬ console tests stayed green. So each one is driven by a real request,
 * with `runningInConsole()` false, and asserted on the FCM request that actually left.
 *
 * A test request runs the kernel's `terminate()` itself, which is where those pushes leave,
 * so a push driven by an HTTP call is asserted straight after it; only the one driven by a
 * bare action call is sent by hand.
 */
class TripPushesAfterTheResponseTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;
    use SendsPushesAfterTheResponse;

    private Restaurant $first;

    private Restaurant $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);
        // The places get the ordinary push, not the ring: this file is about the captain.
        Settings::set('ring.enabled', false);
        $this->configureFirebase();
        $this->fakeFcm();

        $this->first = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
        $this->second = $this->makeRestaurant('سعد الدمياطي', 31.3930, 31.4840);
    }

    public function test_the_holder_hears_trip_order_ready_when_the_place_presses_ready(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->phoneFor($this->captain('كابتن الأول'));

        $this->preparing($b);
        $this->ready($a);
        $this->acceptAs($holder, $a)->assertOk();

        $this->pretendThisIsAWebRequest();

        // The action the button calls, so the moment «after the response» is ours to pick.
        app(MarkOrderReadyAction::class)->execute($this->second->owner, $b->fresh());

        $this->assertSame([], $this->pushesOfType('captain-'.$holder->id, 'trip.order_ready'), 'nothing leaves before the response');
        $this->sendWhatWaitsForTheResponse();

        $pushes = $this->pushesOfType('captain-'.$holder->id, 'trip.order_ready');
        $this->assertCount(1, $pushes);
        $this->assertSame((string) $b->id, $pushes[0]['data']['order_id']);
        $this->assertSame('trip', $pushes[0]['data']['kind']);
        $this->assertStringContainsString('سعد الدمياطي', $pushes[0]['notification']['title']);
        $this->assertSame(
            [],
            array_filter($this->pushesOfType('captain-'.$holder->id, 'order.assigned'), fn (array $m) => $m['data']['order_id'] === (string) $b->id),
            'one push for the sibling, not two',
        );
    }

    public function test_the_captain_hears_trip_order_cancelled_when_the_office_cancels(): void
    {
        $captain = $this->phoneFor($this->captain());
        $order = $this->ready($this->basket([$this->first])->first());
        $this->acceptAs($captain, $order)->assertOk();

        $this->pretendThisIsAWebRequest();

        Sanctum::actingAs($this->admin());
        $this->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason_code' => 'customer_request'])
            ->assertOk();

        $pushes = $this->pushesOfType('captain-'.$captain->id, 'trip.order_cancelled');
        $this->assertCount(1, $pushes);
        $this->assertStringContainsString('أُزيل من رحلتك', $pushes[0]['notification']['title']);
        $this->assertSame($order->order_number, $pushes[0]['data']['order_number'], 'the app draws its own sentence and needs the number');
        $this->assertNull($captain->fresh()->active_order_id);
    }

    public function test_the_holder_hears_it_when_the_customer_cancels_the_sibling_still_in_the_kitchen(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->phoneFor($this->captain());

        $this->preparing($b);
        $this->ready($a);
        $this->acceptAs($holder, $a)->assertOk();

        $this->pretendThisIsAWebRequest();

        Sanctum::actingAs($b->user);
        $this->withHeaders(['X-App' => 'app'])
            ->postJson("/api/v1/orders/{$b->order_group_id}/cancel", ['reason_code' => 'changed_mind'])
            ->assertOk();

        $this->assertSame('cancelled', $b->fresh()->status->value);
        $this->assertCount(1, $this->pushesOfType('captain-'.$holder->id, 'trip.order_cancelled'));
        // The sibling on the trip is untouched.
        $this->assertSame($holder->id, $a->fresh()->driver_id);
    }

    public function test_a_captain_who_accepts_is_rung_about_the_orders_that_join_the_trip(): void
    {
        // Ready nearby before anybody was on shift, so nobody has been asked about it — it
        // reaches this captain through the backlog their accept hands them.
        $waiting = $this->ready($this->basket([$this->second])->first());
        $captain = $this->phoneFor($this->captain());
        $taken = $this->ready($this->basket([$this->first])->first());
        $this->assertSame([$taken->id], $this->openOffers($captain));

        $this->pretendThisIsAWebRequest();

        $this->acceptAs($captain, $taken)->assertOk();

        $offers = $this->pushesOfType('captain-'.$captain->id, 'offer.new');
        $joined = array_values(array_filter($offers, fn (array $m) => $m['data']['order_id'] === (string) $waiting->id));
        $this->assertCount(1, $joined);
        $this->assertStringContainsString('ضمن رحلتك الحالية', $joined[0]['notification']['body']);
    }

    // --------------------------------------------------------------- helpers

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

    private function admin(): User
    {
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);

        $admin = User::query()->create(['name' => 'Admin', 'phone' => '01000000099', 'type' => 'admin', 'is_active' => true]);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
