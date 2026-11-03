<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\DriverPresence;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\SendsPushesAfterTheResponse;
use Tests\TestCase;

/**
 * doc 99 §3 — «the kitchen is not the captain». An order that has its captain while it
 * still cooks stays on the place's «قيد التحضير» with its countdown; «جاهز», the timer and
 * an extension work until `ready_at`, and each tells the captain.
 */
class KitchenIsNotTheCaptainTest extends TestCase
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
        Settings::set('dispatch.strategy', 'auto_assign');
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'UTC'));

        $this->place = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_place_still_sees_it_preparing_with_its_countdown_then_ready_moves_it_and_tells_the_captain(): void
    {
        [$order, $captain] = $this->assignedWhileCooking();

        $board = $this->asPlace()->getJson('/api/v1/restaurant/orders?filter[queue]=preparing')->assertOk()->json('data');
        $this->assertSame([$order->id], array_column($board, 'id'));
        $this->assertFalse($board[0]['kitchen_ready']);
        $this->assertSame('driver_assigned', $board[0]['status']);
        $this->assertNotNull($board[0]['prep_due_at']);
        $this->assertSame([], $this->asPlace()->getJson('/api/v1/restaurant/orders?filter[queue]=with_driver')->json('data'));

        $counts = $this->asPlace()->getJson('/api/v1/restaurant/orders/new-count')->assertOk()->json('data');
        $this->assertSame(1, $counts['preparing']);
        $this->assertSame(0, $counts['with_driver']);

        $this->travel(2)->minutes();
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/ready")->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DriverAssigned, $fresh->status, 'the captain already has it');
        $this->assertSame($captain->id, $fresh->driver_id);
        $this->assertNotNull($fresh->ready_at);
        $this->assertSame('restaurant', $fresh->ready_source->value);
        $this->assertSame(120, $fresh->prep_actual_seconds);
        $this->assertTrue($fresh->kitchen_ready);

        $push = Notification::query()->where('user_id', $captain->user_id)->where('order_id', $order->id)->where('type', 'trip.order_ready')->sole();
        $this->assertStringContainsString('كنج بروست', $push->payload['title_ar']);

        $this->assertSame([$order->id], array_column($this->asPlace()->getJson('/api/v1/restaurant/orders?filter[queue]=with_driver')->json('data'), 'id'));
        $this->assertSame([], $this->asPlace()->getJson('/api/v1/restaurant/orders?filter[queue]=preparing')->json('data'));

        // Pressed twice: nothing more happens.
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/ready")->assertStatus(422)->assertJsonPath('error', 'invalid_transition');
        $this->assertSame(1, Notification::query()->where('order_id', $order->id)->where('type', 'trip.order_ready')->count());
    }

    public function test_the_prep_timer_sets_ready_at_and_tells_both(): void
    {
        [$order, $captain] = $this->assignedWhileCooking(prep: 5);

        $this->travel(6)->minutes();
        $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DriverAssigned, $fresh->status);
        $this->assertSame('timer', $fresh->ready_source->value);
        $this->assertNotNull($fresh->ready_at);
        $this->assertSame(1, Notification::query()->where('user_id', $captain->user_id)->where('type', 'trip.order_ready')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $this->place->owner->id)->where('type', 'order.prep_timer_expired')->count());

        // The next tick leaves it alone.
        $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();
        $this->assertSame(1, Notification::query()->where('type', 'trip.order_ready')->count());
    }

    public function test_extending_after_assignment_still_works_and_tells_the_captain_the_new_time(): void
    {
        [$order, $captain] = $this->assignedWhileCooking(prep: 7);
        $due = $order->fresh()->prep_due_at;

        $this->asPlace()->patchJson("/api/v1/restaurant/orders/{$order->id}/prep-time", ['extra_minutes' => 5])->assertOk();

        $this->assertTrue($order->fresh()->prep_due_at->equalTo($due->copy()->addMinutes(5)));
        $push = Notification::query()->where('user_id', $captain->user_id)->where('type', 'trip.order_prep_extended')->sole();
        $this->assertSame("أُضيفت \u{2066}5\u{2069} دقيقة — الجاهزية المتوقعة \u{2066}12:12\u{2069}", $push->payload['body_ar']);
        $this->assertStringContainsString('12:12', $push->payload['body_ar'], 'the new time on the Cairo clock (10:00Z + 7 + 5 = 12:12 Cairo)');

        // Once ready, extending is over.
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/ready")->assertOk();
        $this->asPlace()->patchJson("/api/v1/restaurant/orders/{$order->id}/prep-time", ['extra_minutes' => 5])->assertStatus(422);
    }

    public function test_the_office_may_extend_it_too(): void
    {
        [$order, $captain] = $this->assignedWhileCooking(prep: 7);
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/orders/{$order->id}/extend-prep", ['extra_minutes' => 20])
            ->assertOk();

        $this->assertSame(1, Notification::query()->where('user_id', $captain->user_id)->where('type', 'trip.order_prep_extended')->count());
    }

    public function test_the_captain_and_the_customer_see_kitchen_ready(): void
    {
        [$order, $captain] = $this->assignedWhileCooking();

        $trip = $this->asCaptain($captain)->getJson('/api/v1/driver/trip')->assertOk()->json('data');
        $this->assertFalse($trip['orders'][0]['kitchen_ready']);
        $this->assertSame('preparing', $trip['stops'][0]['state']);

        Sanctum::actingAs($order->user);
        $tracking = $this->withHeader('X-App', 'app')->getJson("/api/v1/orders/{$order->order_group_id}/tracking")->assertOk()->json('data');
        $this->assertFalse($tracking['sub_orders'][0]['kitchen_ready']);
        $detail = $this->getJson("/api/v1/orders/{$order->order_group_id}")->assertOk()->json('data');
        $this->assertFalse($detail['sub_orders'][0]['kitchen_ready']);
    }

    public function test_the_auto_push_leaves_on_the_offers_channel_with_via_auto_over_the_web_path(): void
    {
        $this->configureFirebase();
        $this->fakeFcm();

        $captain = $this->captain('كابتن قريب', 31.3842, 31.4842);
        $captain->user->deviceTokens()->create(['token' => 'captain-auto', 'platform' => 'android', 'app' => 'driver', 'device_id' => 'dev-auto']);
        DriverPresence::touch($captain);

        $order = $this->basket([$this->place])->first();
        $order->update(['status' => OrderStatus::Confirmed, 'prep_minutes' => 5]);

        $this->pretendThisIsAWebRequest();

        // The place's own button, over HTTP: the push leaves after the response.
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/start-preparing", ['restaurant_order_ref' => 'P-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing');

        $pushes = $this->pushesOfType('captain-auto', 'order.assigned');
        $this->assertCount(1, $pushes);
        $this->assertSame('auto', $pushes[0]['data']['via']);
        $this->assertSame((string) $order->id, $pushes[0]['data']['order_id']);
        $this->assertSame('0', $pushes[0]['data']['kitchen_ready']);
        $this->assertSame('cr7_offers', $pushes[0]['android']['notification']['channel_id']);
    }

    public function test_a_1_0_23_captain_app_finds_the_auto_assigned_order_as_its_active_order(): void
    {
        [$order, $captain] = $this->assignedWhileCooking();

        $active = $this->asCaptain($captain)->withHeader('X-App-Version', '1.0.23')->getJson('/api/v1/driver/orders/active')->assertOk()->json('data');
        $this->assertSame($order->id, $active['id']);
        $this->assertSame([], $this->asCaptain($captain)->withHeader('X-App-Version', '1.0.23')->getJson('/api/v1/driver/offers')->json('data'), 'nothing to accept');
    }

    public function test_in_broadcast_mode_an_office_forced_order_still_cooking_stays_on_preparing_and_can_be_marked_ready(): void
    {
        Settings::set('dispatch.strategy', 'broadcast');
        $captain = $this->captain('كابتن', 31.3842, 31.4842);
        $order = $this->preparing($this->basket([$this->place])->first(), 15);

        Sanctum::actingAs($this->admin());
        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", ['driver_id' => $captain->id, 'reason_code' => 'manual_dispatch', 'mode' => 'force'])
            ->assertOk();

        $this->assertSame('office', $order->fresh()->assigned_via);
        $this->assertSame([$order->id], array_column($this->asPlace()->getJson('/api/v1/restaurant/orders?filter[queue]=preparing')->json('data'), 'id'));

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/ready")->assertOk();
        $this->assertNotNull($order->fresh()->ready_at);
        $this->assertSame(OrderStatus::DriverAssigned, $order->fresh()->status);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: Order, 1: Driver} */
    private function assignedWhileCooking(int $prep = 7): array
    {
        $captain = $this->captain('كابتن قريب', 31.3842, 31.4842);
        DriverPresence::touch($captain);

        $order = $this->basket([$this->place])->first();
        $order->update(['status' => OrderStatus::Confirmed, 'prep_minutes' => $prep]);
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/start-preparing", ['restaurant_order_ref' => 'P-1'])->assertOk();

        $this->assertSame($captain->id, $order->fresh()->driver_id, 'precondition: assigned while cooking');

        return [$order->fresh(), $captain];
    }

    private function asPlace(): static
    {
        Sanctum::actingAs($this->place->owner);

        return $this->withHeader('X-App', 'restaurant');
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
