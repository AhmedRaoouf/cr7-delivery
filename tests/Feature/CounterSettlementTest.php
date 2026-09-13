<?php

namespace Tests\Feature;

use App\Actions\Driver\DeliverOrderAction;
use App\Actions\Driver\PickupOrderAction;
use App\Enums\AdminScopeType;
use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Models\User;
use App\Support\DeliveryCommission;
use App\Support\DriverEarningCalculator;
use App\Support\DriverLedger;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-103 · D-104 — the money at the counter, from both sides.
 *
 * Operator, 2026-09-13: «خت منه فلوسه الاوردرات اللي عملها وخد فلوسها كاش وعطيته فلوسه
 * الاوردرات اللي عملها واتبعتت انستا باي او فودافون كاش». And, asked how the food money
 * reaches a place on a cash order: «الكابتن يدفع للمكان وقت الاستلام».
 */
class CounterSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        Settings::set(DeliveryCommission::SETTING, 15);

        $this->admin = User::factory()->create(['type' => 'admin']);
        $this->admin->assignRole('super_admin');
        $this->admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
    }

    // ------------------------------------------------------------ the captain

    public function test_the_captain_pays_the_place_at_pickup_and_the_platform_owes_it_back_at_once(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, 'cash', food: 100, fee: 20, status: 'arrived_restaurant');

        app(PickupOrderAction::class)->execute($driver, $order, $order->getRawOriginal('pickup_otp'));

        $driver->refresh();
        $this->assertSame(100.0, (float) $driver->wallet_balance, 'the advance is the platform\'s debt from the second he pays');
        $this->assertSame(0.0, (float) $driver->cash_in_hand);
        $this->assertSame(100.0, (float) $order->fresh()->place_paid_amount);

        // A forced pickup and a real one cannot pay the place twice.
        DriverLedger::recordPlaceAdvance($driver, $order->fresh());
        $this->assertSame(1, $driver->transactions()->where('type', 'place_advance')->count());
    }

    public function test_the_unsettled_document_puts_cash_orders_beside_online_ones(): void
    {
        $driver = $this->driver();
        $this->deliver($driver, $this->order($driver, 'cash', food: 100, fee: 20));
        $this->deliver($driver, $this->order($driver, 'instapay', food: 80, fee: 20));

        $document = DriverLedger::unsettled($driver->fresh());

        $this->assertCount(1, $document['cash_orders']);
        $this->assertCount(1, $document['online_orders']);

        $cash = $document['cash_orders'][0];
        $this->assertSame(120.0, $cash['collected']);
        $this->assertSame(100.0, $cash['paid_to_place']);
        $this->assertSame(17.0, $cash['earning']);
        $this->assertSame(3.0, $cash['platform_share'], '15 % of the 20 fee');

        $this->assertSame(17.0, $document['online_orders'][0]['earning']);

        // 3 owed by the captain, 17 owed to him: the platform owes him 14.
        $this->assertSame(-14.0, $document['totals']['net_due_to_platform']);
        $this->assertSame('platform_owes', $document['balances']['direction']);
        $this->assertSame(14.0, $document['balances']['amount']);
        $this->assertSame(0.0, $document['opening_balance']);
    }

    public function test_the_captain_reads_the_same_document_the_office_settles_from(): void
    {
        $driver = $this->driver();
        $this->deliver($driver, $this->order($driver, 'cash', food: 100, fee: 20));

        Sanctum::actingAs($driver->user);
        $mine = $this->withHeaders(['X-App' => 'app'])->getJson('/api/v1/driver/earnings/unsettled')->assertOk();

        Sanctum::actingAs($this->admin);
        $office = $this->withHeaders(['X-App' => 'admin'])->getJson("/api/v1/admin/drivers/{$driver->id}/unsettled")->assertOk();

        $this->assertSame($office->json('data.totals'), $mine->json('data.totals'));
        $this->assertSame('captain_owes', $mine->json('data.balances.direction'));
        $this->assertSame('على الكابتن للمنصة', $mine->json('data.balances.label'));
    }

    public function test_a_full_settlement_writes_a_statement_and_closes_every_row(): void
    {
        $driver = $this->driver();
        $this->deliver($driver, $this->order($driver, 'cash', food: 100, fee: 20));
        $this->deliver($driver, $this->order($driver, 'vodafone_cash', food: 50, fee: 40));

        Sanctum::actingAs($this->admin);
        $response = $this->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/drivers/{$driver->id}/settle", ['mode' => 'full', 'method' => 'cash', 'reference' => 'الخزنة'])
            ->assertCreated();

        $settlement = Settlement::query()->findOrFail($response->json('data.settlement.id'));

        $this->assertSame('paid', $settlement->status->value);
        $this->assertSame('driver', $settlement->party_type->value);
        // The 120 he carried came in; the 151 he was owed went out — the 100 he paid the
        // place, and his 17 and 34. Across the counter, 31 went his way: 34 − 3.
        $this->assertSame(120.0, (float) $settlement->cash_received);
        $this->assertSame(151.0, (float) $settlement->paid_out);
        $this->assertSame(0.0, (float) $settlement->closing_balance);
        $this->assertSame(0, DriverTransaction::query()->where('driver_id', $driver->id)->whereNull('settlement_id')->count());
        $this->assertSame(2, $settlement->items()->count());

        $after = DriverLedger::unsettled($driver->fresh());
        $this->assertSame([], $after['cash_orders']);
        $this->assertSame('settled', $after['balances']['direction']);

        $push = Notification::query()->where('user_id', $driver->user_id)->where('type', 'settlement.recorded')->sole();
        $this->assertStringContainsString('استلمت من المنصة ‪31.00‬ ج.م', $push->payload['body_ar']);
        $this->assertStringContainsString('حسابك مسوّى بالكامل', $push->payload['body_ar']);
    }

    public function test_a_partial_settlement_carries_what_is_left_into_the_next_statement(): void
    {
        $driver = $this->driver();
        $this->deliver($driver, $this->order($driver, 'cash', food: 100, fee: 20));

        Sanctum::actingAs($this->admin);
        $this->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/drivers/{$driver->id}/settle", ['cash_received' => 50])
            ->assertCreated();

        // He owed 3 and handed over 50 of the 120 he carried: 70 still out, 117 still owed.
        $document = DriverLedger::unsettled($driver->fresh());
        $this->assertSame(-47.0, $document['balances']['net_due_to_platform']);
        $this->assertSame(-47.0, $document['opening_balance']);
    }

    public function test_a_captain_is_not_settled_by_a_period_statement(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($this->admin);
        $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/settlements/generate', [
                'party_type' => 'driver', 'party_id' => $driver->id,
                'period_start' => now()->subWeek()->toDateString(), 'period_end' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'driver_settles_from_ledger');
    }

    public function test_a_forced_delivery_pays_the_captain_like_a_real_one(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, 'cash', food: 100, fee: 20);

        Sanctum::actingAs($this->admin);
        $this->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/orders/{$order->id}/force-status", ['status' => 'delivered', 'note' => 'سُلّم ولم يُدخل الكود'])
            ->assertOk();

        $driver->refresh();
        $this->assertSame(117.0, (float) $driver->wallet_balance);
        $this->assertSame(120.0, (float) $driver->cash_in_hand);
    }

    // -------------------------------------------------------------- the place

    public function test_a_places_statement_pays_its_online_food_and_nothing_on_cash(): void
    {
        $driver = $this->driver();
        $cash = $this->order($driver, 'cash', food: 100, fee: 20);
        $restaurant = $cash->restaurant;
        $online = $this->order($driver, 'instapay', food: 80, fee: 20, restaurantId: $restaurant->id);
        $this->deliver($driver, $cash);
        $this->deliver($driver, $online);

        Sanctum::actingAs($this->admin);
        $owed = $this->withHeaders(['X-App' => 'admin'])->getJson('/api/v1/admin/finance/restaurants')->assertOk();
        $row = collect($owed->json('data'))->firstWhere('restaurant_id', $restaurant->id);
        $this->assertSame(80.0, (float) $row['owed_online']);
        $this->assertSame(100.0, (float) $row['paid_at_pickup']);

        $response = $this->withHeaders(['X-App' => 'admin'])->postJson('/api/v1/admin/settlements/generate', [
            'party_type' => 'restaurant', 'party_id' => $restaurant->id,
            'period_start' => now()->subDay()->toDateString(), 'period_end' => now()->toDateString(),
        ])->assertCreated();

        // The old formula subtracted the customer's cash **total** — 120 — and left the
        // place owing the platform 20 for a cash order it had sold.
        $this->assertSame(80.0, (float) $response->json('data.net_amount'));
        $this->assertSame(100.0, (float) $response->json('data.cash_collected'));
        $types = collect($response->json('data.items'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['cash_paid_at_pickup', 'online'], $types);

        // Claimed: the same orders cannot be paid twice.
        $this->withHeaders(['X-App' => 'admin'])->postJson('/api/v1/admin/settlements/generate', [
            'party_type' => 'restaurant', 'party_id' => $restaurant->id,
            'period_start' => now()->subDay()->toDateString(), 'period_end' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('error', 'nothing_to_settle');

        $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/settlements/'.$response->json('data.id').'/pay', ['reference' => 'تحويل 7'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $restaurant->user_id, 'type' => 'settlement.paid']);
    }

    // ------------------------------------------------------------ plumbing

    private function driver(): Driver
    {
        $user = User::query()->create([
            'name' => 'كابتن محمود',
            'phone' => '010'.random_int(10000000, 99999999),
            'type' => 'driver',
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0,
            'is_online' => true,
            'is_available' => false,
        ]);
    }

    private function order(Driver $driver, string $method, float $food, float $fee, string $status = 'on_the_way', ?int $restaurantId = null): Order
    {
        $customer = $this->makeCustomer();
        $restaurant = $restaurantId ? Restaurant::query()->findOrFail($restaurantId) : $this->makeRestaurant('مكان '.Str::random(4));

        $group = OrderGroup::query()->create([
            'group_number' => 'G'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => $method,
            'rollup_status' => $status,
            'subtotal' => $food,
            'delivery_fee' => $fee,
            'total' => $food + $fee,
        ]);

        $order = Order::query()->create([
            'order_group_id' => $group->id,
            'order_number' => 'O'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'group_sequence' => 1,
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'restaurant_name_snapshot' => $restaurant->name_ar,
            'driver_id' => $driver->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => $status,
            'payment_method' => $method,
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'distance_km' => 2.4,
            'subtotal' => $food,
            'commission_amount' => 0,
            'delivery_fee_share' => $fee,
            'total' => $food + $fee,
            'pickup_otp' => '2718',
            'delivery_otp' => '4417',
        ]);

        $order->update([
            'driver_earning' => DriverEarningCalculator::calculate($order, $driver),
            'platform_delivery_margin' => DriverEarningCalculator::platformMargin($order, $driver),
        ]);

        return $order->fresh();
    }

    private function deliver(Driver $driver, Order $order): void
    {
        $driver->update(['active_order_id' => $order->id]);
        app(DeliverOrderAction::class)->execute($driver->fresh(), $order->fresh(), '4417', null);
    }
}
