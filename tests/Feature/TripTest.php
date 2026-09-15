<?php

namespace Tests\Feature;

use App\Actions\Admin\AdminCancelOrderAction;
use App\Actions\Admin\ForceStatusAction;
use App\Actions\Admin\ReassignDriverAction;
use App\Actions\Admin\UnassignDriverAction;
use App\Actions\Driver\DeliverOrderAction;
use App\Actions\Driver\PickupOrderAction;
use App\Actions\Restaurant\CreateDirectOrderAction;
use App\Enums\AdminScopeType;
use App\Enums\ErrandStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Support\DriverTrip;
use App\Support\ErrandDispatcher;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 63 D-116 / D-117 — the trip.
 *
 * «خلّي الـ Delivery الواحد أصلًا ممكن يجيله أكتر من order مع بعض طالما الأماكن قريبة من
 * بعض وهو يقبل أو يرفض … والـ Delivery الواحد يبقى معاه maximum تلت orders for one trip.»
 *
 * Every row of doc 63 §7 that is about a captain carrying more than one order, and the
 * derived focus (`active_order_id`) and «carries nothing» (`is_available`) after every path
 * that assigns, releases, picks up, delivers or cancels.
 */
class TripTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    /** The place the captain's trip starts at. */
    private Restaurant $home;

    /** A kilometre from it — close enough to join the trip. */
    private Restaurant $near;

    /** Three kilometres from it — too far. */
    private Restaurant $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);

        $this->home = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
        $this->near = $this->makeRestaurant('سعد الدمياطي', 31.3930, 31.4840);
        $this->far = $this->makeRestaurant('ابن البلد', 31.4140, 31.4840);
    }

    // ------------------------------------------------------------------ gates

    public function test_a_captain_carrying_an_order_is_offered_one_from_a_place_near_their_trip(): void
    {
        $captain = $this->carrying($this->home);

        $order = $this->ready($this->single($this->near));

        $this->assertContains($order->id, $this->openOffers($captain));

        // «ضمن رحلتك الحالية» — on the push and on the card.
        $push = Notification::query()->where('user_id', $captain->user_id)->where('order_id', $order->id)->where('type', 'offer.new')->sole();
        $this->assertStringContainsString('ضمن رحلتك الحالية', $push->payload['body_ar']);
        $this->assertStringContainsString('on your current trip', $push->payload['body_en']);

        $offer = $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk()->json('data.0');
        $this->assertSame($order->id, $offer['order_id']);
        $this->assertStringContainsString('ضمن رحلتك الحالية', $offer['summary']);
        $this->assertSame(1, $offer['trip']['count']);
        $this->assertSame(2, $offer['trip']['after']);
        $this->assertSame(3, $offer['trip']['max']);
        $this->assertSame('معك الآن ‪1‬ · ستصبح ‪2‬ من ‪3‬', $offer['trip']['label']);
        $this->assertNull($offer['basket']);

        $this->acceptAs($captain, $order)->assertOk();
        $this->assertSame(2, DriverTrip::count($captain));
    }

    public function test_a_place_far_from_the_trip_is_offered_to_somebody_else(): void
    {
        $captain = $this->carrying($this->home);
        $free = $this->captain('كابتن متفرغ');

        $order = $this->ready($this->single($this->far));

        // Gate 3 is about the places, not the captain: both stand at the same spot.
        $this->assertNotContains($order->id, $this->openOffers($captain));
        $this->assertContains($order->id, $this->openOffers($free));
    }

    public function test_a_full_trip_is_offered_nothing_more(): void
    {
        Settings::set('dispatch.max_orders_per_trip', 2);
        $captain = $this->carrying($this->home, 2);

        $order = $this->ready($this->single($this->near));

        $this->assertNotContains($order->id, $this->openOffers($captain));
    }

    public function test_with_batching_off_a_trip_is_one_order(): void
    {
        Settings::set('dispatch.batching_enabled', false);
        $captain = $this->carrying($this->home);

        $order = $this->ready($this->single($this->near));

        $this->assertNotContains($order->id, $this->openOffers($captain));
    }

    public function test_a_basket_bigger_than_the_maximum_still_goes_to_a_captain_carrying_nothing(): void
    {
        Settings::set('dispatch.max_orders_per_trip', 1);
        $busy = $this->carrying($this->home);
        $free = $this->captain('كابتن متفرغ');

        [$a, $b] = $this->basket([$this->home, $this->near]);
        $this->ready($a);
        $this->ready($b);

        $this->assertSame([], $this->openOffers($busy));
        $this->assertSame([$a->id, $b->id], $this->openOffers($free));

        // Never refused by the cap: two bags on a trip of one.
        $this->acceptAs($free, $a)->assertOk();
        $this->assertSame(2, DriverTrip::count($free));
    }

    public function test_lowering_the_maximum_takes_nothing_away_and_offers_nothing_more(): void
    {
        $captain = $this->carrying($this->home, 2);

        Settings::set('dispatch.max_orders_per_trip', 1);

        $this->assertSame(2, DriverTrip::count($captain));
        $order = $this->ready($this->single($this->near));
        $this->assertNotContains($order->id, $this->openOffers($captain));
    }

    public function test_an_offer_whose_place_stopped_being_near_before_the_press_is_refused_in_words(): void
    {
        Settings::set('dispatch.batch_radius_km', 5);
        $captain = $this->carrying($this->home);
        $order = $this->ready($this->single($this->far));
        $this->assertContains($order->id, $this->openOffers($captain));

        Settings::set('dispatch.batch_radius_km', 1);

        $this->acceptAs($captain, $order)
            ->assertStatus(409)
            ->assertJsonPath('error', 'too_far_for_trip')
            ->assertJsonPath('message', 'مكان هذا الطلب بعيد عن أماكن رحلتك الحالية');
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_a_direct_order_joins_a_trip_like_any_order(): void
    {
        $captain = $this->carrying($this->home);
        $this->near->update(['direct_orders_enabled' => true]);

        $direct = app(CreateDirectOrderAction::class)->execute($this->near->owner, $this->near->fresh(), [
            'guest_customer_name' => 'محمود',
            'guest_customer_phone' => '01012345678',
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'food_payment' => 'paid_at_restaurant',
            'restaurant_order_ref' => 'D-1',
            'ready_now' => true,
        ], 'direct-'.uniqid());

        $this->assertContains($direct->id, $this->openOffers($captain));
        $this->assertNull(DriverTrip::offerBasket($captain, $direct), 'a direct order is never a basket');
    }

    // --------------------------------------------------------------- the trip

    public function test_the_trip_lists_its_stops_pickups_first_ready_before_the_kitchen(): void
    {
        Settings::set('delivery.first_order_free', false);
        $captain = $this->captain();

        [$a, $b] = $this->basket([$this->home, $this->near]);
        $this->preparing($b, 12);
        $this->acceptAs($captain, $this->ready($a))->assertOk();

        $s = $this->ready($this->single($this->makeRestaurant('بوكس ع السريع', 31.3860, 31.4840)));
        $this->acceptAs($captain, $s)->assertOk();
        PickupOrderAction::complete($captain->fresh(), $s->fresh(), OrderStatusActorType::Driver, $captain->user_id);

        $trip = $this->asCaptain($captain)->getJson('/api/v1/driver/trip')->assertOk()->json('data');

        $this->assertSame(
            [['pickup', $a->id, true], ['pickup', $b->id, false], ['dropoff', $a->id, true], ['dropoff', $b->id, false], ['dropoff', $s->id, true]],
            array_map(fn ($stop) => [$stop['kind'], $stop['order_id'], $stop['assigned']], $trip['stops']),
        );

        [$first, $kitchen] = $trip['stops'];
        $this->assertSame('ready', $first['state']);
        $this->assertNull($first['ready_in_seconds']);
        $this->assertSame('كنج بروست', $first['place']['name']);
        $this->assertSame('preparing', $kitchen['state']);
        $this->assertGreaterThan(0, $kitchen['ready_in_seconds']);
        $this->assertSame($a->order_group_id, $kitchen['basket_id']);
        $this->assertArrayHasKey('address', $trip['stops'][2]);
        $this->assertArrayHasKey('customer_area', $trip['stops'][2]);

        $this->assertSame(['count' => 2, 'held' => 1, 'max' => 3, 'batching' => true, 'radius_km' => 1.5], $trip['capacity']);

        // Every order — the held sibling too — with its basket and where it first stops.
        $orders = collect($trip['orders'])->keyBy('id');
        $this->assertSame([0, 1, 4], [$orders[$a->id]['stop_index'], $orders[$b->id]['stop_index'], $orders[$s->id]['stop_index']]);
        $this->assertSame([true, false, true], [$orders[$a->id]['assigned'], $orders[$b->id]['assigned'], $orders[$s->id]['assigned']]);
        $this->assertSame($a->order_group_id, $orders[$b->id]['basket_id']);
        $this->assertArrayHasKey('customer', $orders[$a->id]);
        $this->assertArrayHasKey('money', $orders[$a->id]);

        // D-103 — the cash the trip still needs: two places to pay (the third is paid),
        // three doors to collect at.
        $this->assertEquals(
            round($a->fresh()->placePaidAtPickup() + $b->fresh()->placePaidAtPickup(), 2),
            $trip['cash']['pay_places_total'],
        );
        $this->assertEquals(
            round($a->fresh()->cashToCollect() + $b->fresh()->cashToCollect() + $s->fresh()->cashToCollect(), 2),
            $trip['cash']['collect_total'],
        );

        // The focus is the first stop the captain can act on.
        $this->assertSame($a->id, $captain->fresh()->active_order_id);
    }

    public function test_an_empty_trip_is_an_empty_trip(): void
    {
        $captain = $this->captain();

        $this->asCaptain($captain)->getJson('/api/v1/driver/trip')
            ->assertOk()
            ->assertJsonPath('data.orders', [])
            ->assertJsonPath('data.stops', [])
            ->assertJsonPath('data.capacity.count', 0)
            // JSON has one number type: `0.0` travels as `0`.
            ->assertJsonPath('data.cash.pay_places_total', 0)
            ->assertJsonPath('data.cash.collect_total', 0);
    }

    public function test_the_focus_and_carries_nothing_follow_every_move_of_the_trip(): void
    {
        $captain = $this->captain();
        [$a, $b] = $this->basket([$this->home, $this->near]);
        $this->preparing($b);

        // Accept.
        $this->acceptAs($captain, $this->ready($a))->assertOk();
        $this->assertTrip($captain, $a, false);

        // The basket rule.
        $this->ready($b->fresh());
        $this->assertTrip($captain, $a, false);

        // Pickup: the pickup still to make comes first.
        PickupOrderAction::complete($captain->fresh(), $a->fresh(), OrderStatusActorType::Driver, $captain->user_id);
        $this->assertTrip($captain, $b, false);

        // Delivery: the rest of the trip is untouched.
        DeliverOrderAction::complete($captain->fresh(), $a->fresh(), null, OrderStatusActorType::Driver, $captain->user_id);
        $this->assertTrip($captain, $b, false);

        // An admin's cancellation takes the last order out of the trip — and says so.
        app(AdminCancelOrderAction::class)->execute($this->operator(), $b->fresh(), 'customer_request', null, false);
        $this->assertTrip($captain, null, true);
        $this->assertDatabaseHas('notifications', ['user_id' => $captain->user_id, 'order_id' => $b->id, 'type' => 'trip.order_cancelled']);
    }

    public function test_the_office_and_the_captain_releasing_one_order_leave_the_rest_of_the_trip(): void
    {
        $captain = $this->captain();
        $other = $this->captain('كابتن آخر');
        $admin = $this->operator();

        $x = $this->ready($this->single($this->home));
        $y = $this->ready($this->single($this->near));
        $z = $this->ready($this->single($this->home));
        foreach ([$x, $y, $z] as $order) {
            $this->acceptAs($captain, $order)->assertOk();
        }
        $this->assertSame(3, DriverTrip::count($captain));

        // Unassign one.
        app(UnassignDriverAction::class)->execute($admin, $y->fresh(), 'driver_unresponsive', null, requeue: false);
        $this->assertSame(2, DriverTrip::count($captain));
        $this->assertNotSame($y->id, $captain->fresh()->active_order_id);

        // Reassign one to another captain: theirs now, gone from this trip.
        app(ReassignDriverAction::class)->execute($admin, $z->fresh(), $other->id, 'nearest', null);
        $this->assertTrip($captain, $x, false);
        $this->assertTrip($other, $z, false);

        // The captain steps off the last one.
        $this->asCaptain($captain)->postJson("/api/v1/driver/orders/{$x->id}/cancel", ['reason_code' => 'emergency'])->assertOk();
        $this->assertTrip($captain, null, true);
    }

    public function test_a_forced_cancellation_takes_the_order_out_of_the_trip(): void
    {
        $captain = $this->carrying($this->home);
        $order = Order::query()->where('driver_id', $captain->id)->sole();

        app(ForceStatusAction::class)->execute($this->operator(), $order, OrderStatus::Cancelled, 'اختبار');

        $this->assertTrip($captain, null, true);
        $this->assertDatabaseHas('notifications', ['user_id' => $captain->user_id, 'order_id' => $order->id, 'type' => 'trip.order_cancelled']);
    }

    public function test_an_order_in_delivery_issue_still_counts_in_the_trip(): void
    {
        $captain = $this->carrying($this->home);
        $order = Order::query()->where('driver_id', $captain->id)->sole();
        PickupOrderAction::complete($captain->fresh(), $order->fresh(), OrderStatusActorType::Driver, $captain->user_id);

        $this->asCaptain($captain)
            ->postJson("/api/v1/driver/orders/{$order->id}/issue", ['category' => 'customer_unreachable'])
            ->assertCreated();

        $this->assertSame(OrderStatus::DeliveryIssue, $order->fresh()->status);
        $this->assertTrip($captain, $order, false);
        $this->assertSame(1, DriverTrip::count($captain));
    }

    public function test_a_captain_who_goes_offline_keeps_the_trip_is_offered_nothing_and_may_come_back(): void
    {
        $captain = $this->carrying($this->home);

        $this->asCaptain($captain)->postJson('/api/v1/driver/status', ['is_online' => false])->assertOk();
        $order = $this->ready($this->single($this->near));
        $this->assertNotContains($order->id, $this->openOffers($captain));
        $this->assertSame(1, DriverTrip::count($captain));

        // Going online while carrying orders is the point now — and the backlog that fits
        // the trip reaches them on the way in.
        $this->asCaptain($captain)->postJson('/api/v1/driver/status', ['is_online' => true])
            ->assertOk()
            ->assertJsonPath('data.is_online', true);
        $this->assertContains($order->id, $this->openOffers($captain));
    }

    public function test_signing_out_mid_trip_keeps_the_trip(): void
    {
        $captain = $this->carrying($this->home);
        $order = Order::query()->where('driver_id', $captain->id)->sole();

        $this->asCaptain($captain)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame($captain->id, $order->fresh()->driver_id);
        $this->assertTrip($captain, $order, false);
        $this->assertFalse($captain->fresh()->is_online);
    }

    /**
     * doc 63 §7 — the 1.0.3 captain app. It reads «the» active order and `offers.first`, and
     * must keep working: one order at a time, each completed before the next is drawn.
     */
    public function test_the_installed_app_sees_the_focus_and_its_offers_accept_while_on_a_trip(): void
    {
        $captain = $this->carrying($this->home);
        $carried = Order::query()->where('driver_id', $captain->id)->sole();

        $this->asCaptain($captain)->getJson('/api/v1/driver/orders/active')
            ->assertOk()
            ->assertJsonPath('data.id', $carried->id)
            ->assertJsonPath('data.status', 'driver_assigned')
            ->assertJsonStructure(['data' => ['customer', 'place', 'captain', 'money', 'restaurant', 'pickup_code_required', 'cash_to_collect']]);

        $order = $this->ready($this->single($this->near));

        // Offers while on a trip — `[]` before doc 63 — and the keys the old card reads.
        $first = $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk()->json('data.0');
        $this->assertSame($order->id, $first['order_id']);
        foreach (['order_number', 'restaurant', 'your_earning', 'summary', 'distance_to_place_km', 'cash_to_collect', 'expires_at'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        $this->acceptAs($captain, $order)->assertOk();

        // Still one order at a time on the old screen: the focus, and when it is delivered,
        // the next.
        $focus = $captain->fresh()->active_order_id;
        $this->assertContains($focus, [$carried->id, $order->id]);
        DeliverOrderAction::complete($captain->fresh(), Order::query()->findOrFail($focus), null, OrderStatusActorType::Driver, $captain->user_id);
        $this->asCaptain($captain)->getJson('/api/v1/driver/orders/active')
            ->assertOk()
            ->assertJsonPath('data.id', $focus === $carried->id ? $order->id : $carried->id);
    }

    public function test_a_location_ping_is_filed_under_the_focus(): void
    {
        $captain = $this->carrying($this->home);
        $order = Order::query()->where('driver_id', $captain->id)->sole();

        $this->asCaptain($captain)->postJson('/api/v1/driver/location', ['latitude' => 31.3846, 'longitude' => 31.4846])->assertOk();

        $this->assertDatabaseHas('driver_locations', ['driver_id' => $captain->id, 'order_id' => $order->id]);
    }

    // ---------------------------------------------------------------- errands

    public function test_a_captain_on_an_errand_is_offered_no_orders_and_cannot_accept_one(): void
    {
        $captain = $this->captain();
        $held = $this->ready($this->single($this->home));
        $this->assertContains($held->id, $this->openOffers($captain));

        $this->errandFor($captain);

        // Offered nothing new…
        $order = $this->ready($this->single($this->near));
        $this->assertNotContains($order->id, $this->openOffers($captain));

        // …and the offer already in hand answers in words.
        $this->acceptAs($captain, $held)
            ->assertStatus(409)
            ->assertJsonPath('error', 'on_an_errand')
            ->assertJsonPath('message', 'أنت في مشوار الآن — أنهِه أولًا');

        // Nor is it on the recovery list, where «قبول» could only refuse.
        $this->asCaptain($captain)->getJson('/api/v1/driver/offers')->assertOk()->assertJsonPath('data', []);
    }

    public function test_taking_an_errand_closes_the_order_offers_in_hand(): void
    {
        $captain = $this->captain();
        $order = $this->ready($this->single($this->home));
        $errand = $this->errandFor(null, ErrandStatus::Open);

        $this->asCaptain($captain)->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();

        $this->assertDatabaseHas('order_driver_attempts', ['order_id' => $order->id, 'driver_id' => $captain->id, 'result' => 'superseded']);
    }

    public function test_a_captain_carrying_orders_is_offered_no_errands_and_cannot_take_one(): void
    {
        $captain = $this->carrying($this->home);
        $free = $this->captain('كابتن متفرغ');
        $errand = $this->errandFor(null, ErrandStatus::Open);

        app(ErrandDispatcher::class)->publish($errand);

        $this->assertSame(0, Notification::query()->where('user_id', $captain->user_id)->where('type', 'errand.new')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $free->user_id)->where('type', 'errand.new')->count());

        $this->asCaptain($captain)->postJson("/api/v1/driver/errands/{$errand->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('error', 'driver_busy');
    }

    public function test_the_office_cannot_hand_an_order_to_a_captain_on_an_errand(): void
    {
        $captain = $this->captain();
        $this->errandFor($captain);
        $order = $this->ready($this->single($this->home));

        foreach (['force', 'offer'] as $mode) {
            $this->asAdmin()
                ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", ['driver_id' => $captain->id, 'reason_code' => 'nearest', 'mode' => $mode])
                ->assertStatus(422)
                ->assertJsonPath('error', 'driver_on_an_errand')
                ->assertJsonPath('message', 'هذا الكابتن في مشوار الآن');
        }
    }

    // ------------------------------------------------------------ the office

    public function test_the_eligible_list_shows_each_captains_trip_and_the_gate_that_would_stop_them(): void
    {
        Settings::set('dispatch.max_orders_per_trip', 2);
        $full = $this->carrying($this->home, 2, 'كابتن ممتلئ');
        $room = $this->carrying($this->far, 1, 'كابتن بعيد');
        $free = $this->captain('كابتن متفرغ');

        $order = $this->ready($this->single($this->home));

        $rows = collect($this->asAdmin()->getJson("/api/v1/admin/orders/{$order->id}/eligible-drivers")->assertOk()->json('data'))->keyBy('driver_id');

        $this->assertSame(
            ['is_busy' => true, 'trip_count' => 2, 'trip_max' => 2, 'can_take' => false, 'refusal' => 'trip_full'],
            array_intersect_key($rows[$full->id], array_flip(['is_busy', 'trip_count', 'trip_max', 'can_take', 'refusal'])),
        );
        $this->assertSame(['trip_count' => 1, 'can_take' => false, 'refusal' => 'too_far_for_trip'], array_intersect_key($rows[$room->id], array_flip(['trip_count', 'can_take', 'refusal'])));
        $this->assertSame(['is_busy' => false, 'trip_count' => 0, 'can_take' => true, 'refusal' => null], array_intersect_key($rows[$free->id], array_flip(['is_busy', 'trip_count', 'can_take', 'refusal'])));
    }

    public function test_the_office_needs_room_on_the_trip_and_leaves_distance_to_the_operator(): void
    {
        Settings::set('dispatch.max_orders_per_trip', 2);
        $full = $this->carrying($this->home, 2);
        $far = $this->carrying($this->far);

        $order = $this->ready($this->single($this->home));

        foreach (['force', 'offer'] as $mode) {
            $this->asAdmin()
                ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", ['driver_id' => $full->id, 'reason_code' => 'nearest', 'mode' => $mode])
                ->assertStatus(422)
                ->assertJsonPath('error', 'driver_trip_full')
                ->assertJsonPath('message', 'رحلة هذا الكابتن مكتملة');
        }

        // Three kilometres from their trip — the operator's call. As an offer, the captain's
        // «قبول» skips the distance gate too.
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", ['driver_id' => $far->id, 'reason_code' => 'nearest'])
            ->assertOk();
        $this->acceptAs($far, $order)->assertOk();
        $this->assertSame(2, DriverTrip::count($far));
    }

    public function test_the_fleet_prints_the_trip_beside_its_focus(): void
    {
        $captain = $this->carrying($this->home, 2);

        $row = collect($this->asAdmin()->getJson('/api/v1/admin/dashboard/live-drivers')->assertOk()->json('data'))
            ->firstWhere('id', $captain->id);

        $this->assertSame(2, $row['trip_count']);
        $this->assertSame($captain->fresh()->active_order_id, $row['active_order_id']);
        $this->assertArrayNotHasKey('national_id', $row, 'withCount must not widen the row');
        $this->assertArrayNotHasKey('wallet_balance', $row);

        $listed = collect($this->asAdmin()->getJson('/api/v1/admin/drivers')->assertOk()->json('data'))->firstWhere('id', $captain->id);
        $this->assertSame(2, $listed['trip_count']);
    }

    public function test_the_dispatch_doctor_prints_the_trip_gates(): void
    {
        $captain = $this->carrying($this->home, 1, 'كابتن رامي');
        $order = $this->ready($this->single($this->far));

        $this->artisan('cr7:dispatch-doctor', ['order' => $order->id])
            ->expectsOutputToContain('dispatch.max_orders_per_trip')
            ->expectsOutputToContain('dispatch.batch_radius_km')
            ->assertSuccessful();

        $this->assertSame(DriverTrip::TOO_FAR, DriverTrip::refusal($captain->fresh(), $order));
    }

    // ------------------------------------------------------------- settings

    public function test_the_four_settings_read_their_defaults_where_no_row_exists(): void
    {
        foreach (['dispatch.one_captain_per_basket', 'dispatch.batching_enabled', 'dispatch.max_orders_per_trip', 'dispatch.batch_radius_km'] as $key) {
            Settings::forget($key);
        }
        Setting::query()->where('key', 'like', 'dispatch.%')->delete();

        $this->assertTrue(DriverTrip::oneCaptainPerBasket());
        $this->assertTrue(DriverTrip::batching());
        $this->assertSame(3, DriverTrip::max());
        $this->assertSame(1.5, DriverTrip::radiusKm());

        $dispatch = collect($this->asAdmin()->getJson('/api/v1/admin/settings/schema')->assertOk()->json('data'))->firstWhere('key', 'dispatch');
        $fields = collect($dispatch['fields'])->keyBy('key');
        $this->assertSame(true, $fields['dispatch.one_captain_per_basket']['value']);
        $this->assertSame(true, $fields['dispatch.batching_enabled']['value']);
        $this->assertSame(3, $fields['dispatch.max_orders_per_trip']['value']);
        $this->assertEquals(1.5, $fields['dispatch.batch_radius_km']['value']);
        $this->assertSame(['min' => 1, 'max' => 6], array_intersect_key($fields['dispatch.max_orders_per_trip'], ['min' => 0, 'max' => 0]));
    }

    public function test_the_dead_multi_restaurant_mode_is_gone(): void
    {
        Setting::query()->create(['key' => 'dispatch.mode_multi_restaurant', 'value' => 'driver_per_restaurant', 'group' => 'dispatch']);

        (require database_path('migrations/2026_09_23_090000_one_captain_per_basket_and_the_trip.php'))->up();

        $this->assertDatabaseMissing('settings', ['key' => 'dispatch.mode_multi_restaurant']);

        $this->seed(SettingsSeeder::class);
        $this->assertDatabaseMissing('settings', ['key' => 'dispatch.mode_multi_restaurant']);
    }

    // --------------------------------------------------------------- helpers

    /** A single-place order from this place, from a customer of its own. */
    private function single(Restaurant $place): Order
    {
        return $this->basket([$place])->first();
    }

    /** A captain standing at the trip's place, carrying `$count` orders from `$place`. */
    private function carrying(Restaurant $place, int $count = 1, string $name = 'كابتن رامي'): Driver
    {
        $captain = $this->captain($name, (float) $this->home->latitude + 0.0005, (float) $this->home->longitude + 0.0005);

        for ($i = 0; $i < $count; $i++) {
            $order = $this->single($place);
            // The offer, written the way `dispatchRound` writes it, but only to this captain
            // — every other captain in the test stays out of it.
            $order = $this->preparing($order);
            $order->update(['status' => OrderStatus::SearchingDriver, 'ready_at' => now()]);
            $order->driverAttempts()->create(['driver_id' => $captain->id, 'round' => 1, 'result' => 'sent', 'sent_at' => now()]);
            $this->acceptAs($captain, $order)->assertOk();
        }

        return $captain->fresh();
    }

    private function assertTrip(Driver $captain, ?Order $focus, bool $carriesNothing): void
    {
        $fresh = $captain->fresh();
        $this->assertSame($focus?->id, $fresh->active_order_id, 'the focus');
        $this->assertSame($carriesNothing, $fresh->is_available, '«carries nothing»');
    }

    private function errandFor(?Driver $captain, ErrandStatus $status = ErrandStatus::Assigned): ErrandRequest
    {
        return ErrandRequest::query()->create([
            'reference' => 'ER-'.random_int(1000, 9999),
            'user_id' => $this->makeCustomer()->id,
            'driver_id' => $captain?->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => $status,
            'pickup_address_text' => 'صيدلية جمصة',
            'pickup_lat' => 31.384,
            'pickup_lng' => 31.484,
            'dropoff_address_text' => 'شارع البحر',
            'dropoff_lat' => 31.386,
            'dropoff_lng' => 31.486,
            'details' => 'دواء',
            'distance_km' => 2.1,
            'offered_fee' => 40,
            'assigned_at' => $captain === null ? null : now(),
        ]);
    }

    private ?User $admin = null;

    private function operator(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);

        $this->admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);
        $this->admin->assignRole('super_admin');
        $this->admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $this->admin;
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->operator());

        return $this->withHeaders(['X-App' => 'admin', 'Accept-Language' => 'ar']);
    }
}
