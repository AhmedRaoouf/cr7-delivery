<?php

namespace Tests\Feature;

use App\Actions\Customer\ConfirmOrderGroupAction;
use App\Actions\Restaurant\AcceptOrderAction;
use App\Actions\Restaurant\MarkOrderReadyAction;
use App\Actions\Restaurant\StartPreparingAction;
use App\Enums\AdminScopeType;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 100 — «التوقيت بتاع كل مرحلة لكل أوردر… وعايزهم بدقة». Every transition below
 * happens at its own known instant, and `GET /admin/orders/{id}` must hand back each of
 * those instants to the second, on Cairo's clock, with the seconds between them.
 *
 * The dates are in November, after Egypt's clocks went back: Cairo is UTC+2, so
 * 08:00:00Z reads 10:00:00+02:00.
 */
class OrderStageTimesTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    private Restaurant $place;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        Settings::set('delivery.first_order_free', false);
        Settings::set('ring.enabled', false);
        Settings::set('payment.instapay_enabled', true);

        $this->place = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);

        $this->admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);
        $this->admin->assignRole('super_admin');
        $this->admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_stage_of_a_transfer_order_is_timed_to_the_second(): void
    {
        $this->at('08:00:00');
        $customer = $this->makeCustomer();
        [$order] = $this->basket([$this->place], $customer, 'instapay');

        $this->at('08:01:10');
        app(AcceptOrderAction::class)->execute($this->place->owner, $order->fresh(), 20, null);
        $this->assertSame('awaiting_customer_confirmation', $order->fresh()->status->value);

        $this->at('08:02:00');
        app(ConfirmOrderGroupAction::class)->execute($customer, $order->orderGroup->fresh());
        $this->assertSame('awaiting_payment', $order->fresh()->status->value);

        // The receipt itself is not what this test is about.
        $this->at('08:03:30');
        $payment = $order->orderGroup->fresh()->payment;
        $payment->update(['status' => 'under_review']);

        $this->at('08:04:00');
        $this->asAdmin()->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertOk();
        $this->assertSame('confirmed', $order->fresh()->status->value);

        $this->at('08:05:00');
        app(StartPreparingAction::class)->execute($this->place->owner, $order->fresh(), '101');

        $this->at('08:22:30');
        $captain = $this->captain('كابتن رامي');
        app(MarkOrderReadyAction::class)->execute($this->place->owner, $order->fresh());

        $this->at('08:23:00');
        $this->acceptAs($captain, $order)->assertOk();

        $this->at('08:30:00');
        $this->captainPosts($captain, $order, 'arrived-restaurant');

        $this->at('08:33:20');
        $this->captainPosts($captain, $order, 'pickup', ['otp' => $order->fresh()->pickup_otp]);

        $this->at('08:33:40');
        $this->captainPosts($captain, $order, 'on-the-way');

        $this->at('08:45:00');
        $this->captainPosts($captain, $order, 'arrived-customer');

        $this->at('08:47:15');
        $this->captainPosts($captain, $order, 'deliver', ['otp' => $order->fresh()->delivery_otp]);
        $this->assertSame('delivered', $order->fresh()->status->value);

        $this->at('09:00:00');
        $data = $this->detail($order)->json('data');

        $this->assertSame('Africa/Cairo', $data['stages_timezone']);
        $this->assertStages([
            ['placed', '10:00:00', null, 'customer'],
            ['accepted', '10:01:10', 70, 'restaurant'],
            ['confirmed', '10:02:00', 50, 'customer'],
            ['payment_verified', '10:04:00', 120, 'admin'],
            ['preparing', '10:05:00', 60, 'restaurant'],
            ['ready', '10:22:30', 1050, 'restaurant'],
            ['driver_assigned', '10:23:00', 30, 'driver'],
            ['arrived_restaurant', '10:30:00', 420, 'driver'],
            ['picked_up', '10:33:20', 200, 'driver'],
            ['arrived_customer', '10:45:00', 700, 'driver'],
            ['delivered', '10:47:15', 135, 'driver'],
        ], $data['stages']);

        $assigned = collect($data['stages'])->firstWhere('key', 'driver_assigned');
        $this->assertSame($captain->id, $assigned['driver']['id']);
        $this->assertSame('كابتن رامي', $assigned['driver']['name']);
        $this->assertSame('accept', $assigned['via']);
        $this->assertSame('restaurant', collect($data['stages'])->firstWhere('key', 'ready')['source']);

        $this->assertSame([], $data['earlier_captains']);
        $this->assertSame([
            'prep_actual_seconds' => 1050,
            'prep_promised_seconds' => 1200,
            'prep_initial_promised_seconds' => 1200,
            'prep_over_by_seconds' => -150,
            'wait_at_place_seconds' => 200,
            'delivery_leg_seconds' => 835,
            'total_seconds' => 2835,
        ], $data['durations']);
    }

    public function test_the_stages_are_on_cairos_clock_in_summer_too(): void
    {
        // July: Egypt's daylight time, UTC+3.
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:00:00', 'UTC'));
        [$order] = $this->basket([$this->place]);

        $placed = collect($this->detail($order)->json('data.stages'))->firstWhere('key', 'placed');
        $this->assertSame('2026-07-15T11:00:00+03:00', $placed['at']);
    }

    public function test_a_direct_order_skips_the_customers_confirmation_and_names_its_own_stages(): void
    {
        $this->place->update(['direct_orders_enabled' => true]);
        $captain = $this->captain('كابتن سعيد');

        $this->at('12:00:00');
        Sanctum::actingAs($this->place->owner);
        $id = $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/restaurant/direct-orders', [
                'guest_customer_name' => 'محمد',
                'guest_customer_phone' => '01012345678',
                'delivery_address_text' => 'شارع البحر',
                'delivery_latitude' => 31.3835,
                'delivery_longitude' => 31.4835,
                'zone_id' => $this->zone->id,
                'restaurant_order_ref' => 'A-17',
                'declared_order_value' => 150,
                'food_payment' => 'collect_by_driver',
                'ready_now' => false,
                'prep_minutes' => 15,
            ], ['Idempotency-Key' => 'direct-stage-times'])
            ->assertCreated()
            ->json('data.order_id');
        $order = Order::query()->findOrFail($id);

        $this->at('12:12:00');
        $captain->update(['location_updated_at' => now()]);
        app(MarkOrderReadyAction::class)->execute($this->place->owner, $order->fresh());

        $this->at('12:13:05');
        $this->acceptAs($captain, $order)->assertOk();

        $this->at('12:20:00');
        $this->captainPosts($captain, $order, 'arrived-restaurant');

        $this->at('12:21:00');
        $this->captainPosts($captain, $order, 'pickup', ['otp' => $order->fresh()->pickup_otp]);

        $this->at('12:21:10');
        $this->captainPosts($captain, $order, 'on-the-way');

        $this->at('12:40:00');
        $this->captainPosts($captain, $order, 'deliver', ['collected_amount' => (float) $order->fresh()->cashToCollect()]);

        $data = $this->detail($order)->json('data');

        // No «قبل المكان», no «أكّد العميل»: the place made the order itself, and the
        // captain went straight from «في الطريق» to «تم التسليم» without «وصل للعميل».
        $this->assertStages([
            ['placed', '14:00:00', null, 'restaurant'],
            ['preparing', '14:00:00', 0, 'restaurant'],
            ['ready', '14:12:00', 720, 'restaurant'],
            ['driver_assigned', '14:13:05', 65, 'driver'],
            ['arrived_restaurant', '14:20:00', 415, 'driver'],
            ['picked_up', '14:21:00', 60, 'driver'],
            ['delivered', '14:40:00', 1140, 'driver'],
        ], $data['stages']);

        $this->assertSame(720, $data['durations']['prep_actual_seconds']);
        $this->assertSame(900, $data['durations']['prep_promised_seconds']);
        $this->assertSame(-180, $data['durations']['prep_over_by_seconds']);
        $this->assertSame(60, $data['durations']['wait_at_place_seconds']);
        $this->assertSame(1140, $data['durations']['delivery_leg_seconds']);
        $this->assertSame(2400, $data['durations']['total_seconds']);
    }

    public function test_a_reassigned_order_shows_the_current_captains_times_and_keeps_the_earlier_one(): void
    {
        $this->at('09:00:00');
        [$order] = $this->basket([$this->place]);
        $first = $this->captain('كابتن الأول');
        $second = $this->captain('كابتن الثاني', 31.3846, 31.4846);

        $this->at('09:01:00');
        app(AcceptOrderAction::class)->execute($this->place->owner, $order->fresh(), 15, null);

        $this->at('09:02:00');
        app(StartPreparingAction::class)->execute($this->place->owner, $order->fresh(), '7');

        $this->at('09:15:00');
        $first->update(['location_updated_at' => now()]);
        $second->update(['location_updated_at' => now()]);
        app(MarkOrderReadyAction::class)->execute($this->place->owner, $order->fresh());

        $this->at('09:16:00');
        $this->acceptAs($first, $order)->assertOk();

        $this->at('09:24:00');
        $this->captainPosts($first, $order, 'arrived-restaurant');

        // The office takes it from him while he stands at the counter.
        $this->at('09:30:00');
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", ['driver_id' => $second->id, 'reason_code' => 'driver_unresponsive', 'mode' => 'force'])
            ->assertOk();

        // Before the new captain arrives, the first one's arrival is not his.
        $this->at('09:31:00');
        $data = $this->detail($order)->json('data');
        $keys = array_column($data['stages'], 'key');
        $this->assertNotContains('arrived_restaurant', $keys);
        $assigned = collect($data['stages'])->firstWhere('key', 'driver_assigned');
        $this->assertSame('2026-11-05T11:30:00+02:00', $assigned['at']);
        $this->assertSame($second->id, $assigned['driver']['id']);
        $this->assertSame('office', $assigned['via']);
        $this->assertSame('admin', $assigned['actor']);

        $this->at('09:36:00');
        $this->captainPosts($second, $order, 'arrived-restaurant');

        $this->at('09:38:30');
        $this->captainPosts($second, $order, 'pickup', ['otp' => $order->fresh()->pickup_otp]);

        $data = $this->detail($order)->json('data');
        $this->assertStages([
            ['placed', '11:00:00', null, 'customer'],
            ['accepted', '11:01:00', 60, 'restaurant'],
            ['confirmed', '11:01:00', 0, 'system'],
            ['preparing', '11:02:00', 60, 'restaurant'],
            ['ready', '11:15:00', 780, 'restaurant'],
            ['driver_assigned', '11:30:00', 900, 'admin'],
            ['arrived_restaurant', '11:36:00', 360, 'driver'],
            ['picked_up', '11:38:30', 150, 'driver'],
        ], $data['stages']);
        $this->assertSame(150, $data['durations']['wait_at_place_seconds']);
        $this->assertNull($data['durations']['total_seconds']);

        $this->assertCount(1, $data['earlier_captains']);
        $earlier = $data['earlier_captains'][0];
        $this->assertSame($first->id, $earlier['driver']['id']);
        $this->assertSame('كابتن الأول', $earlier['driver']['name']);
        $this->assertSame('2026-11-05T11:16:00+02:00', $earlier['assigned_at']);
        $this->assertSame('2026-11-05T11:24:00+02:00', $earlier['arrived_restaurant_at']);
        $this->assertSame('2026-11-05T11:30:00+02:00', $earlier['ended_at']);
        $this->assertSame('replaced', $earlier['ended_by']);
        $this->assertSame('driver_unresponsive', $earlier['reason_code']);
    }

    public function test_an_unassigned_then_cancelled_order_shows_no_current_captain_and_the_cancellation(): void
    {
        $this->at('09:00:00');
        [$order] = $this->basket([$this->place]);
        $captain = $this->captain('كابتن الأول');

        $this->at('09:01:00');
        app(AcceptOrderAction::class)->execute($this->place->owner, $order->fresh(), 15, null);
        $this->at('09:02:00');
        app(StartPreparingAction::class)->execute($this->place->owner, $order->fresh(), '7');
        $this->at('09:15:00');
        $captain->update(['location_updated_at' => now()]);
        app(MarkOrderReadyAction::class)->execute($this->place->owner, $order->fresh());
        $this->at('09:16:00');
        $this->acceptAs($captain, $order)->assertOk();
        $this->at('09:20:00');
        $this->captainPosts($captain, $order, 'arrived-restaurant');

        $this->at('09:25:00');
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/unassign-driver", ['reason_code' => 'vehicle_problem', 'requeue' => false])
            ->assertOk();

        $this->at('09:40:00');
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason_code' => 'no_driver_available', 'refund' => false])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertNull($fresh->driver_id);
        // The columns the unassign left behind — what the panel must not show as current.
        $this->assertNotNull($fresh->driver_assigned_at);
        $this->assertNotNull($fresh->arrived_restaurant_at);

        $data = $this->detail($order)->json('data');
        $this->assertStages([
            ['placed', '11:00:00', null, 'customer'],
            ['accepted', '11:01:00', 60, 'restaurant'],
            ['confirmed', '11:01:00', 0, 'system'],
            ['preparing', '11:02:00', 60, 'restaurant'],
            ['ready', '11:15:00', 780, 'restaurant'],
            ['cancelled', '11:40:00', 1500, 'admin'],
        ], $data['stages']);
        $this->assertSame('no_driver_available', collect($data['stages'])->firstWhere('key', 'cancelled')['reason_code']);

        $this->assertCount(1, $data['earlier_captains']);
        $this->assertSame($captain->id, $data['earlier_captains'][0]['driver']['id']);
        $this->assertSame('2026-11-05T11:20:00+02:00', $data['earlier_captains'][0]['arrived_restaurant_at']);
        $this->assertSame('2026-11-05T11:25:00+02:00', $data['earlier_captains'][0]['ended_at']);
        $this->assertSame('removed_by_admin', $data['earlier_captains'][0]['ended_by']);
        $this->assertSame('vehicle_problem', $data['earlier_captains'][0]['reason_code']);

        $this->assertNull($data['durations']['total_seconds']);
        $this->assertNull($data['durations']['wait_at_place_seconds']);
    }

    public function test_a_stage_the_office_forced_falls_back_to_its_history_row(): void
    {
        $this->at('09:00:00');
        [$order] = $this->basket([$this->place]);

        // `force-status` writes the status and the history row, and no `*_at` column.
        $this->at('09:10:00');
        $this->asAdmin()
            ->postJson("/api/v1/admin/orders/{$order->id}/force-status", ['status' => 'cancelled', 'note' => 'طلب تجريبي'])
            ->assertOk();
        $this->assertNull($order->fresh()->cancelled_at);

        $cancelled = collect($this->detail($order)->json('data.stages'))->firstWhere('key', 'cancelled');
        $this->assertSame('2026-11-05T11:10:00+02:00', $cancelled['at']);
        $this->assertSame(600, $cancelled['since_previous_seconds']);
        $this->assertTrue($cancelled['forced']);
        $this->assertSame('admin', $cancelled['actor']);
    }

    public function test_the_fields_the_detail_already_carried_are_unchanged(): void
    {
        $this->at('09:00:00');
        [$order] = $this->basket([$this->place]);
        $this->at('09:01:00');
        app(AcceptOrderAction::class)->execute($this->place->owner, $order->fresh(), 15, null);

        $data = $this->detail($order)->json('data');

        // The raw columns are still there, as the API has always serialised them (UTC).
        $this->assertSame('2026-11-05T09:00:00.000000Z', $data['placed_at']);
        $this->assertSame('2026-11-05T09:01:00.000000Z', $data['accepted_at']);
        $this->assertSame('accepted', $data['timeline'][1]['to_status']);
        $this->assertSame('confirmed', $data['timeline'][2]['to_status']);
        $this->assertCount(3, $data['timeline']);
        foreach (['restaurant', 'user', 'items', 'attempts', 'refunds', 'amendments', 'dispatch', 'order_group', 'status', 'kitchen_ready'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        // The list never loads the history, so it does not pay for the stages.
        $row = collect($this->asAdmin()->getJson('/api/v1/admin/orders')->assertOk()->json('data'))->firstWhere('id', $order->id);
        $this->assertArrayNotHasKey('stages', $row);
        $this->assertArrayNotHasKey('durations', $row);
    }

    // ---------------------------------------------------------------- helpers

    private function at(string $utcTime): void
    {
        Carbon::setTestNow(Carbon::parse("2026-11-05 {$utcTime}", 'UTC'));
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeaders(['X-App' => 'admin', 'Accept-Language' => 'ar']);
    }

    private function detail(Order $order): TestResponse
    {
        return $this->asAdmin()->getJson("/api/v1/admin/orders/{$order->id}")->assertOk();
    }

    /** @param array<string, mixed> $body */
    private function captainPosts(Driver $captain, Order $order, string $step, array $body = []): void
    {
        $this->asCaptain($captain)->postJson("/api/v1/driver/orders/{$order->id}/{$step}", $body)->assertOk();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int|null, 3: string|null}>  $expected  key, Cairo wall time, seconds since the previous stage, actor
     * @param  list<array<string, mixed>>  $stages
     */
    private function assertStages(array $expected, array $stages): void
    {
        $this->assertSame(
            array_map(fn ($e) => [$e[0], '2026-11-05T'.$e[1].'+02:00', $e[2], $e[3]], $expected),
            array_map(fn ($s) => [$s['key'], $s['at'], $s['since_previous_seconds'], $s['actor']], $stages),
        );
    }
}
