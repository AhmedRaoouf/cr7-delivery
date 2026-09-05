<?php

namespace Tests\Feature;

use App\Actions\Driver\DeliverOrderAction;
use App\Enums\AdminScopeType;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\User;
use App\Support\DeliveryCommission;
use App\Support\DriverEarningCalculator;
use App\Support\DriverLedger;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-70 / D-71 — the 15 % the platform keeps out of every delivery fee, and the two
 * meters that say who is holding whose money afterwards.
 *
 * Operator, 2026-09-05: «سعر الديليفري ده … الأدمين بياخد منه 15% والباقي بيروح
 * للديليفري» and «أخد ألف جنيه كاش، فكده فيه 150 للأدمين».
 *
 * The load-bearing rules are:
 *
 *  1. the two halves of a fee always add back to the fee, at any rate and any amount;
 *  2. a cash trip moves **two** meters, not one, and the cash row is measured against
 *     the cash meter — the version before this wrote the wallet's figure onto it;
 *  3. the rate lives in one setting, so moving it moves every captain and moves no
 *     order that has already been accepted.
 */
class DeliveryCommissionAndLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        Settings::set(DeliveryCommission::SETTING, 15);
    }

    private function driver(array $overrides = []): Driver
    {
        $user = User::query()->create([
            'name' => 'كابتن محمود',
            'phone' => '010'.random_int(10000000, 99999999),
            'type' => 'driver',
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        return Driver::query()->create(array_merge([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            // The standard rule: a percentage rather than a per-captain number, with
            // `0` meaning «whatever the setting says» (D-70).
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0,
            'is_online' => true,
            'is_available' => false,
        ], $overrides));
    }

    private function order(Driver $driver, string $paymentMethod, float $deliveryFee, float $total): Order
    {
        $customer = $this->makeCustomer();
        $restaurant = $this->makeRestaurant('سندوتش عمر');

        $group = OrderGroup::query()->create([
            'group_number' => 'G'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => $paymentMethod,
            'rollup_status' => 'on_the_way',
            'subtotal' => $total - $deliveryFee,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
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
            'status' => 'on_the_way',
            'payment_method' => $paymentMethod,
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'distance_km' => 2.4,
            'subtotal' => $total - $deliveryFee,
            'delivery_fee_share' => $deliveryFee,
            'total' => $total,
            'delivery_otp' => '4417',
        ]);

        $order->update([
            'driver_earning' => DriverEarningCalculator::calculate($order, $driver),
            'platform_delivery_margin' => DriverEarningCalculator::platformMargin($order, $driver),
        ]);

        $driver->update(['active_order_id' => $order->id]);

        return $order->fresh();
    }

    /** `X-App: admin` — doc 12 §1.1 gates every admin route on it, header and all. */
    private function asAdmin(): TestResponse|\Illuminate\Foundation\Testing\TestCase
    {
        return $this->withHeaders(['X-App' => 'admin']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    // ---------------------------------------------------------------- the split

    public function test_the_operators_own_example_lands_on_his_own_numbers(): void
    {
        // «أخد ألف جنيه كاش، فكده فيه 150 للأدمين». A direct order is exactly this
        // case: the customer pays nothing but the delivery fee.
        $this->assertSame(150.0, DeliveryCommission::platformShare(1000));
        $this->assertSame(850.0, DeliveryCommission::driverShare(1000));
    }

    public function test_the_two_halves_always_add_back_to_the_fee(): void
    {
        // 15 % of 25 is 3.75 and of 35 is 5.25 — both round cleanly. The ones that do
        // not are why `driverShare` subtracts instead of multiplying a second time.
        foreach ([20, 25, 30, 35, 40, 55, 60, 70, 80, 90, 100, 150, 33.33, 12.05] as $fee) {
            $this->assertSame(
                round((float) $fee, 2),
                round(DeliveryCommission::platformShare((float) $fee) + DeliveryCommission::driverShare((float) $fee), 2),
                "fee {$fee} did not close",
            );
        }
    }

    public function test_a_captain_on_the_standard_rule_earns_eighty_five_percent_of_the_flyer_price(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, 'cash', deliveryFee: 25, total: 125);

        // «الركن العربي» to a 25 EGP area: 21.25 to the captain, 3.75 to the platform.
        $this->assertSame(21.25, (float) $order->driver_earning);
        $this->assertSame(3.75, (float) $order->platform_delivery_margin);
    }

    public function test_moving_the_rate_moves_every_captain_without_touching_a_driver_row(): void
    {
        Settings::set(DeliveryCommission::SETTING, 20);

        $driver = $this->driver();
        $order = $this->order($driver, 'cash', deliveryFee: 100, total: 400);

        $this->assertSame(80.0, (float) $order->driver_earning);
        $this->assertSame(20.0, (float) $order->platform_delivery_margin);
        // The rule on the row never changed — only the setting did.
        $this->assertSame(0.0, (float) $driver->fresh()->earning_value);
    }

    public function test_a_captain_the_operator_put_on_a_fixed_rule_keeps_it(): void
    {
        // `fixed` and `per_km` remain available per captain; the margin then reports
        // whatever is left of the fee rather than a second percentage.
        $driver = $this->driver(['earning_type' => 'fixed', 'earning_value' => 30]);
        $order = $this->order($driver, 'cash', deliveryFee: 25, total: 125);

        $this->assertSame(30.0, (float) $order->driver_earning);
        $this->assertSame(-5.0, (float) $order->platform_delivery_margin);
    }

    // ---------------------------------------------------------------- the ledger

    public function test_a_cash_delivery_moves_both_meters_and_each_row_reports_its_own(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, 'cash', deliveryFee: 25, total: 125);

        app(DeliverOrderAction::class)->execute($driver, $order, '4417', null);

        $driver->refresh();

        $this->assertSame(21.25, (float) $driver->wallet_balance);
        $this->assertSame(125.0, (float) $driver->cash_in_hand);

        $earning = $driver->transactions()->where('type', 'order_earning')->sole();
        $cash = $driver->transactions()->where('type', 'cash_collected')->sole();

        // The regression this test exists for: the cash row used to carry the wallet's
        // number, so the captain's history showed a wallet balance it had never held.
        $this->assertSame(21.25, (float) $earning->balance_after);
        $this->assertSame(125.0, (float) $cash->balance_after);
        $this->assertSame('wallet', DriverLedger::meterOf($earning->type));
        $this->assertSame('cash', DriverLedger::meterOf($cash->type));
    }

    public function test_an_online_delivery_leaves_the_cash_meter_alone(): void
    {
        // «لو هي إنستا باي أو فودافون كاش بتروح لحساب الأدمن» — the captain touched no
        // money, so the platform simply owes them their share.
        $driver = $this->driver();
        $order = $this->order($driver, 'instapay', deliveryFee: 25, total: 125);

        app(DeliverOrderAction::class)->execute($driver, $order, '4417', null);

        $driver->refresh();

        $this->assertSame(21.25, (float) $driver->wallet_balance);
        $this->assertSame(0.0, (float) $driver->cash_in_hand);
        $this->assertSame(-21.25, DriverLedger::balances($driver)['net_due_to_platform']);
    }

    public function test_the_net_on_a_delivery_fee_only_trip_is_the_operators_fifteen_percent(): void
    {
        // The direct-order shape, at his own figure: 1 000 collected, 850 earned,
        // 150 owed to the office.
        $driver = $this->driver();
        $order = $this->order($driver, 'cash', deliveryFee: 1000, total: 1000);

        app(DeliverOrderAction::class)->execute($driver, $order, '4417', null);

        $this->assertSame(150.0, DriverLedger::balances($driver->fresh())['net_due_to_platform']);
    }

    // ---------------------------------------------------------------- settling up

    public function test_the_office_records_cash_in_and_a_payout_in_one_call(): void
    {
        $this->admin();

        $driver = $this->driver();
        $order = $this->order($driver, 'cash', deliveryFee: 1000, total: 1000);
        app(DeliverOrderAction::class)->execute($driver, $order, '4417', null);

        $response = $this->asAdmin()->postJson("/api/v1/admin/drivers/{$driver->id}/settle", [
            'cash_received' => 1000,
            'paid_to_driver' => 850,
            'method' => 'cash',
            'reference' => 'إيصال 41',
        ])->assertCreated();

        $this->assertSame(0.0, (float) $response->json('data.balances.cash_in_hand'));
        $this->assertSame(0.0, (float) $response->json('data.balances.wallet_balance'));
        $this->assertSame(0.0, (float) $response->json('data.balances.net_due_to_platform'));

        $driver->refresh();
        $this->assertSame(0.0, (float) $driver->cash_in_hand);
        $this->assertSame(0.0, (float) $driver->wallet_balance);
    }

    public function test_handing_over_more_cash_than_the_captain_holds_is_refused(): void
    {
        $this->admin();

        $driver = $this->driver();
        $order = $this->order($driver, 'cash', deliveryFee: 25, total: 125);
        app(DeliverOrderAction::class)->execute($driver, $order, '4417', null);

        // A typo here leaves `cash_in_hand` negative, and a negative cash meter is a
        // number nobody can reconcile a month later.
        $this->asAdmin()->postJson("/api/v1/admin/drivers/{$driver->id}/settle", ['cash_received' => 400])
            ->assertStatus(422)
            ->assertJsonPath('error', 'amount_exceeds_cash_in_hand');

        $this->assertSame(125.0, (float) $driver->fresh()->cash_in_hand);
    }

    public function test_a_settlement_with_no_amounts_is_refused(): void
    {
        $this->admin();
        $driver = $this->driver();

        $this->asAdmin()->postJson("/api/v1/admin/drivers/{$driver->id}/settle", ['note' => 'لا شيء'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'nothing_to_settle');
    }

    public function test_the_fleet_balance_sheet_totals_what_the_office_is_owed(): void
    {
        $this->admin();

        $one = $this->driver();
        app(DeliverOrderAction::class)->execute($one, $this->order($one, 'cash', 25, 125), '4417', null);

        $two = $this->driver();
        app(DeliverOrderAction::class)->execute($two, $this->order($two, 'instapay', 25, 125), '4417', null);

        $response = $this->asAdmin()->getJson('/api/v1/admin/finance/drivers')->assertOk();

        $this->assertSame(125.0, (float) $response->json('meta.totals.cash_in_hand'));
        $this->assertSame(42.5, (float) $response->json('meta.totals.wallet_balance'));
        // 125 held − 42.50 owed. One captain owes the office, the other is owed.
        $this->assertSame(82.5, (float) $response->json('meta.totals.net_due_to_platform'));
        $this->assertSame(15.0, (float) $response->json('meta.commission_rate'));
    }

    public function test_a_captains_ledger_names_which_meter_every_row_belongs_to(): void
    {
        $this->admin();

        $driver = $this->driver();
        app(DeliverOrderAction::class)->execute($driver, $this->order($driver, 'cash', 25, 125), '4417', null);

        $response = $this->asAdmin()->getJson("/api/v1/admin/drivers/{$driver->id}/ledger")->assertOk();

        $meters = collect($response->json('data'))->pluck('meter')->sort()->values()->all();
        $this->assertSame(['cash', 'wallet'], $meters);
        $this->assertSame(125.0, (float) $response->json('meta.balances.cash_in_hand'));
        $this->assertSame(103.75, (float) $response->json('meta.balances.net_due_to_platform'));
        $this->assertSame(3.75, (float) $response->json('meta.lifetime.platform_margin'));
    }
}
