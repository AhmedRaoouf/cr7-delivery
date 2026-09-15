<?php

namespace Tests\Feature;

use App\Actions\Admin\ReassignDriverAction;
use App\Actions\Admin\UnassignDriverAction;
use App\Actions\Driver\DeliverOrderAction;
use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderDriverAttempt;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\DispatchService;
use App\Support\DriverEarningCalculator;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 63 D-115 — «لو العميل طالب من مكانين المفروض الـ Delivery اللي يستلم الطلب الأول هو
 * بس اللي يجيله إشعار أما التاني يخلص».
 *
 * A basket from two places ran two independent dispatches: two knocks, two captains, two
 * trips for one customer. The captain who takes the first sub-order now holds the basket,
 * and every sibling reaches them — at accept when it is already looking for a captain, at
 * `ready` when it was still in the kitchen.
 */
class OneCaptainPerBasketTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    private Restaurant $first;

    private Restaurant $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);

        // Two places a kilometre apart, as the two halves of one basket usually are.
        $this->first = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
        $this->second = $this->makeRestaurant('سعد الدمياطي', 31.3930, 31.4840);
    }

    public function test_a_sibling_ready_later_goes_straight_to_the_captain_holding_the_basket(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->captain('كابتن الأول');
        $other = $this->captain('كابتن آخر');

        $this->preparing($b);
        $this->ready($a);
        $this->acceptAs($holder, $a)->assertOk();

        // «Sibling still in the kitchen when the first is accepted — nothing now.»
        $this->assertNull($b->fresh()->driver_id);
        $this->assertSame(OrderStatus::Preparing, $b->fresh()->status);

        $b = $this->ready($b->fresh());

        // No round, no offer to anybody else.
        $this->assertSame($holder->id, $b->driver_id);
        $this->assertSame(OrderStatus::DriverAssigned, $b->status);
        $this->assertSame(0, OrderDriverAttempt::query()->where('order_id', $b->id)->where('driver_id', $other->id)->count());

        // `ready → driver_assigned`, by the system, for the basket.
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $b->id,
            'from_status' => 'ready',
            'to_status' => 'driver_assigned',
            'actor_type' => 'system',
            'reason_code' => 'basket',
        ]);

        // The `accepted` row every release path marks (doc 63 §1.2).
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $b->id, 'driver_id' => $holder->id, 'result' => 'accepted']);

        // The money, exactly as an accept snapshots it.
        $this->assertEquals(DriverEarningCalculator::calculate($b, $holder), (float) $b->driver_earning);
        $this->assertEquals(DriverEarningCalculator::platformMargin($b, $holder), (float) $b->platform_delivery_margin);
        $this->assertGreaterThan(0, (float) $b->driver_earning);

        // One push, to the holder alone — and the customer and the place hear what any
        // assignment tells them.
        $this->assertSame(1, Notification::query()->where('user_id', $holder->user_id)->where('order_id', $b->id)->where('type', 'trip.order_ready')->count());
        $this->assertSame(0, Notification::query()->where('user_id', $holder->user_id)->where('order_id', $b->id)->where('type', 'order.assigned')->count());
        $this->assertSame(0, Notification::query()->where('user_id', $other->user_id)->where('order_id', $b->id)->count());
        $this->assertDatabaseHas('notifications', ['order_id' => $b->id, 'user_id' => $b->user_id, 'type' => 'order.driver_assigned']);
        $this->assertDatabaseHas('notifications', ['order_id' => $b->id, 'user_id' => $this->second->user_id, 'type' => 'order.driver_assigned_restaurant']);

        $row = Notification::query()->where('user_id', $holder->user_id)->where('type', 'trip.order_ready')->sole();
        $this->assertStringContainsString('سعد الدمياطي', $row->payload['title_ar']);
        $this->assertStringContainsString('استلمه ضمن رحلتك', $row->payload['title_ar']);
        $this->assertStringContainsString('#'.$b->order_number, $row->payload['title_en']);

        // The customer sees one captain on both sub-orders.
        $this->assertSame($a->fresh()->driver_id, $b->driver_id);
    }

    public function test_the_prep_timer_is_a_path_to_ready_too(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->captain();

        $this->preparing($b, 10);
        $this->ready($a);
        $this->acceptAs($holder, $a)->assertOk();

        $b->update(['prep_due_at' => now()->subSecond()]);
        $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();

        $this->assertSame($holder->id, $b->fresh()->driver_id);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $b->id, 'to_status' => 'driver_assigned', 'reason_code' => 'basket']);
    }

    public function test_accepting_one_sibling_takes_every_sibling_already_looking_for_a_captain(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $winner = $this->captain('كابتن الأول');
        $loser = $this->captain('كابتن الثاني', 31.3850, 31.4850);

        $this->ready($a);
        $this->ready($b);

        // «Two siblings ready at the same moment, both broadcast».
        $this->assertSame([$a->id, $b->id], $this->openOffers($winner));
        $this->assertSame([$a->id, $b->id], $this->openOffers($loser));

        // One card per basket per captain.
        $this->asCaptain($loser)->getJson('/api/v1/driver/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $a->id)
            ->assertJsonPath('data.0.basket.size', 2);

        $this->acceptAs($winner, $a)->assertOk();

        // The sibling came along in the same transaction, and every other captain's
        // offer on it is over.
        $this->assertSame($winner->id, $b->fresh()->driver_id);
        $this->assertSame(OrderStatus::DriverAssigned, $b->fresh()->status);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $b->id, 'driver_id' => $winner->id, 'result' => 'accepted']);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $b->id, 'driver_id' => $loser->id, 'result' => 'superseded']);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $b->id, 'to_status' => 'driver_assigned', 'actor_type' => 'driver', 'reason_code' => 'basket']);

        // The race: the second «قبول» on the other sibling finds it taken.
        $this->acceptAs($loser, $b)->assertStatus(409)->assertJsonPath('error', 'already_assigned');
        $this->asCaptain($loser)->getJson('/api/v1/driver/offers')->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_lock_is_the_baskets_not_the_orders(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $captain = $this->captain();

        $this->ready($a);
        $this->ready($b);

        // Another accept on the *other* sibling holds the lock this very second.
        $lock = Cache::lock("order_group:{$a->order_group_id}:assign", 5);
        $this->assertTrue($lock->get());

        try {
            $this->acceptAs($captain, $b)->assertStatus(409)->assertJsonPath('error', 'already_assigned');
        } finally {
            $lock->release();
        }

        $this->acceptAs($captain, $b)->assertOk();
    }

    public function test_the_basket_offer_names_every_sibling_the_captain_would_carry(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $captain = $this->captain();

        $this->preparing($b, 12);
        $this->ready($a);

        $offer = $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk()->json('data.0');

        $this->assertSame($a->id, $offer['order_id']);
        $this->assertSame($a->order_group_id, $offer['basket']['id']);
        $this->assertSame(2, $offer['basket']['size']);

        [$lead, $sibling] = $offer['basket']['orders'];
        $this->assertSame([$a->id, 'ready', true], [$lead['order_id'], $lead['state'], $lead['offered']]);
        $this->assertSame([$b->id, 'preparing', false], [$sibling['order_id'], $sibling['state'], $sibling['offered']]);
        $this->assertSame('سعد الدمياطي', $sibling['place']['name']);
        $this->assertNotNull($sibling['distance_km']);
        $this->assertGreaterThan(0, $sibling['ready_in_seconds']);
        $this->assertEquals($lead['your_earning'] + $sibling['your_earning'], $offer['basket']['total_earning']);

        // The trip it would start: nothing carried, two bags after.
        $this->assertSame(['count' => 0, 'held' => 0, 'need' => 2, 'after' => 2, 'max' => 3], array_intersect_key($offer['trip'], array_flip(['count', 'held', 'need', 'after', 'max'])));
    }

    public function test_a_basket_rings_once(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $captain = $this->captain();

        $this->ready($a);
        $this->ready($b);

        // Both rows are written — the attempts table stays complete per order…
        $this->assertSame([$a->id, $b->id], $this->openOffers($captain));

        // …and the phone rang once.
        $this->assertSame(1, Notification::query()->where('user_id', $captain->user_id)->where('type', 'offer.new')->count());

        // A rebroadcast rings the basket again — once, on its lead.
        $dispatch = app(DispatchService::class);
        $dispatch->rebroadcast($a->fresh());
        $dispatch->rebroadcast($b->fresh());
        $this->assertSame(2, Notification::query()->where('user_id', $captain->user_id)->where('type', 'offer.new')->count());
    }

    public function test_rejecting_the_basket_refuses_every_sibling_in_it(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $captain = $this->captain();

        $this->ready($a);
        $this->ready($b);

        $this->rejectAs($captain, $a)->assertOk();

        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $a->id, 'driver_id' => $captain->id, 'result' => 'rejected']);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $b->id, 'driver_id' => $captain->id, 'result' => 'rejected']);
        $this->assertSame(1, (int) $captain->fresh()->rejected_offers);
    }

    public function test_once_the_holder_delivers_the_first_the_sibling_dispatches_normally(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->captain('كابتن الأول');
        $other = $this->captain('كابتن الثاني');

        $this->preparing($b);
        $this->ready($a);
        $this->acceptAs($holder, $a)->assertOk();

        DeliverOrderAction::complete($holder->fresh(), $a->fresh(), null, OrderStatusActorType::Driver, $holder->user_id);

        $b = $this->ready($b->fresh());

        $this->assertNull($b->driver_id);
        $this->assertSame(OrderStatus::SearchingDriver, $b->status);
        $this->assertContains($b->id, $this->openOffers($other));
    }

    public function test_a_holder_who_steps_off_one_sibling_is_not_handed_it_back(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->captain('كابتن الأول');

        $this->ready($a);
        $this->ready($b);
        $this->acceptAs($holder, $a)->assertOk();
        $this->assertSame($holder->id, $b->fresh()->driver_id);

        // Comes on shift after the basket was taken, so the round below is the first
        // time anybody could ask them.
        $other = $this->captain('كابتن الثاني');

        $this->asCaptain($holder)
            ->postJson("/api/v1/driver/orders/{$b->id}/cancel", ['reason_code' => 'emergency'])
            ->assertOk();

        // That order only goes back to dispatch — to everybody but them.
        $b->refresh();
        $this->assertNull($b->driver_id);
        $this->assertSame(OrderStatus::SearchingDriver, $b->status);
        $this->assertContains($b->id, $this->openOffers($other));
        $this->assertNotContains($b->id, $this->openOffers($holder));

        // The rest of the trip stays theirs.
        $this->assertSame($holder->id, $a->fresh()->driver_id);
        $this->assertSame($a->id, $holder->fresh()->active_order_id);
    }

    public function test_an_unassigned_sibling_is_not_handed_back_to_the_captain_it_was_taken_from(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->captain('كابتن الأول');
        $admin = $this->operator();

        $this->ready($a);
        $this->ready($b);
        $this->acceptAs($holder, $a)->assertOk();

        $other = $this->captain('كابتن الثاني');

        app(UnassignDriverAction::class)->execute($admin, $b->fresh(), 'driver_unresponsive', null, requeue: true);

        $b->refresh();
        $this->assertNull($b->driver_id);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $b->id, 'driver_id' => $holder->id, 'result' => 'unassigned_by_admin']);
        $this->assertContains($b->id, $this->openOffers($other));
        $this->assertNotContains($b->id, $this->openOffers($holder));
        $this->assertSame($holder->id, $a->fresh()->driver_id);
    }

    public function test_a_reassigned_siblings_new_captain_holds_it_and_the_earliest_assigned_holds_the_rest(): void
    {
        Settings::set('cart.max_restaurants', 3);
        $third = $this->makeRestaurant('ابن البلد', 31.3880, 31.4840);

        [$a, $b, $c] = $this->basket([$this->first, $this->second, $third]);
        $holder = $this->captain('كابتن الأول');
        $newcomer = $this->captain('كابتن جديد');
        $admin = $this->operator();

        $this->preparing($c);
        $this->ready($a);
        $this->ready($b);
        $this->acceptAs($holder, $a)->assertOk();
        $this->assertSame($holder->id, $b->fresh()->driver_id);

        app(ReassignDriverAction::class)->execute($admin, $b->fresh(), $newcomer->id, 'nearest', null);
        $this->assertSame($newcomer->id, $b->fresh()->driver_id);
        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $b->id, 'driver_id' => $holder->id, 'result' => 'reassigned_by_admin']);

        // `a` was assigned first, so its captain holds the basket's last sibling.
        $this->assertSame($holder->id, $this->ready($c->fresh())->driver_id);
    }

    public function test_with_the_switch_off_each_sibling_finds_its_own_captain(): void
    {
        Settings::set('dispatch.one_captain_per_basket', false);

        [$a, $b] = $this->basket([$this->first, $this->second]);
        $first = $this->captain('كابتن الأول');
        $second = $this->captain('كابتن الثاني');

        $this->preparing($b);
        $this->ready($a);
        $this->acceptAs($first, $a)->assertOk();

        $b = $this->ready($b->fresh());

        // D-30: an ordinary round, offered to everybody the gates let through.
        $this->assertNull($b->driver_id);
        $this->assertSame(OrderStatus::SearchingDriver, $b->status);
        $this->assertContains($b->id, $this->openOffers($second));
    }

    public function test_the_place_sees_the_captain_coming_for_its_order_while_it_is_still_in_the_kitchen(): void
    {
        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->captain('كابتن الأول');

        $this->preparing($b);
        $this->ready($a);

        $board = fn () => $this->asPlace($this->second)
            ->getJson("/api/v1/restaurant/orders/{$b->id}")
            ->assertOk();

        // Nobody holds the basket yet.
        $board()->assertJsonPath('data.basket_captain', null);

        $this->acceptAs($holder, $a)->assertOk();

        $board()
            ->assertJsonPath('data.captain', null)
            ->assertJsonPath('data.basket_captain.id', $holder->id)
            ->assertJsonPath('data.basket_captain.name', 'كابتن الأول')
            ->assertJsonPath('data.basket_captain.phone', $holder->user->phone)
            ->assertJsonPath('data.basket_captain.vehicle_plate', 'ط ر ق 123');

        // Once it has its own captain, the ordinary block carries it.
        $this->ready($b->fresh());
        $board()
            ->assertJsonPath('data.captain.id', $holder->id)
            ->assertJsonPath('data.basket_captain', null);
    }

    public function test_a_free_first_order_pays_the_holder_nothing_and_says_why(): void
    {
        Settings::set('delivery.first_order_free', true);

        [$a, $b] = $this->basket([$this->first, $this->second]);
        $holder = $this->captain();

        $this->preparing($b);
        $this->ready($a);

        $offer = $this->asCaptain($holder)->getJson('/api/v1/driver/offers')->assertOk()->json('data.0');
        $this->assertEquals(0, $offer['basket']['total_earning']);
        $this->assertSame('first_order', $offer['basket']['orders'][1]['free_delivery_reason']);

        $this->acceptAs($holder, $a)->assertOk();
        $b = $this->ready($b->fresh());

        $this->assertSame($holder->id, $b->driver_id);
        $this->assertEquals(0, (float) $b->driver_earning);
        $this->assertSame('first_order', $b->free_delivery_reason);
    }

    // --------------------------------------------------------------- helpers

    private function asPlace(Restaurant $place): static
    {
        Sanctum::actingAs($place->owner);

        return $this->withHeaders(['X-App' => 'restaurant']);
    }

    private function operator(): User
    {
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);

        $admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
