<?php

namespace Tests\Feature;

use App\Actions\Admin\GenerateSettlementAction;
use App\Actions\Customer\CreateOrderAction;
use App\Console\Commands\SettlementCycleCommand;
use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Models\User;
use App\Support\DriverLedger;
use App\Support\OrderParties;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 103 — قرية الناقر's contract: 5 % of the food, a cash order's whole food to the place
 * at the counter, an online order's whole total to the platform, and a statement every
 * 14 days in which whoever owes pays the other.
 */
class PlaceCommissionTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    private Restaurant $naqer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(30.0);
        Settings::set('delivery.first_order_free', false);
        Settings::set('ring.enabled', false);

        $this->naqer = $this->makeRestaurant('قرية الناقر');
        $this->naqer->update(['commission_rate' => 5]);
    }

    public function test_an_order_carries_its_places_rate_and_the_commission_on_its_food(): void
    {
        $order = $this->order(200);

        $this->assertEqualsWithDelta(5.0, (float) $order->commission_rate_snapshot, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $order->commission_amount, 0.001, '5 % of 200');
        $this->assertEqualsWithDelta(190.0, $order->restaurant_amount, 0.001);

        // A place with no contract still pays nothing.
        $other = $this->makeRestaurant('مكان بلا عقد');
        $free = $this->order(200, 'cash', $other);
        $this->assertEqualsWithDelta(0.0, (float) $free->commission_amount, 0.001);
    }

    public function test_a_rate_changed_later_does_not_move_an_order_already_sold(): void
    {
        $order = $this->order(200);
        $this->naqer->update(['commission_rate' => 10]);

        $this->assertEqualsWithDelta(10.0, (float) $order->fresh()->commission_amount, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $this->order(200)->commission_amount, 0.001);
    }

    public function test_on_a_cash_order_the_captain_pays_the_place_its_whole_food(): void
    {
        $order = $this->order(200);
        $captain = $this->captain();
        $order->update(['driver_id' => $captain->id]);

        $this->assertEqualsWithDelta(200.0, $order->placePaidAtPickup(), 0.001, 'not 190');

        DriverLedger::recordPlaceAdvance($captain, $order->fresh());
        $this->assertEqualsWithDelta(200.0, (float) $order->fresh()->place_paid_amount, 0.001);

        // The place sees the commission it owes back on this order.
        $money = OrderParties::money($order->fresh(), 'restaurant');
        $this->assertEqualsWithDelta(10.0, $money['commission_amount'], 0.001);
        $this->assertEqualsWithDelta(-10.0, $money['place_balance'], 0.001);
    }

    public function test_a_statement_nets_the_cash_commission_against_the_online_food(): void
    {
        $this->delivered($this->order(100));                     // cash: place owes 5
        $this->delivered($this->order(200, 'vodafone_cash'));    // online: platform owes 190

        $settlement = $this->settle();

        $this->assertEqualsWithDelta(185.0, (float) $settlement->net_amount, 0.001, '190 − 5');
        $this->assertSame('platform_pays_place', $settlement->breakdown['direction']);
        $this->assertEqualsWithDelta(300.0, (float) $settlement->gross_sales, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $settlement->commission_total, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $settlement->cash_collected, 0.001);
        $this->assertSame(1, $settlement->breakdown['cash_orders_count']);
        $this->assertEqualsWithDelta(5.0, $settlement->breakdown['cash_owed_by_place'], 0.001);
        $this->assertSame(1, $settlement->breakdown['online_orders_count']);
        $this->assertEqualsWithDelta(190.0, $settlement->breakdown['online_total'], 0.001);
        $this->assertEqualsWithDelta(185.0, (float) $settlement->items()->sum('net'), 0.001, 'the lines add up to the statement');
    }

    public function test_a_place_that_only_took_cash_owes_the_platform_its_commission(): void
    {
        $this->delivered($this->order(100));
        $this->delivered($this->order(300));

        $settlement = $this->settle();

        $this->assertEqualsWithDelta(-20.0, (float) $settlement->net_amount, 0.001);
        $this->assertSame('place_pays_platform', $settlement->breakdown['direction']);

        // Paying it tells the place the platform received the money, not that it sent it.
        Sanctum::actingAs($this->admin());
        $this->withHeader('X-App', 'admin')->postJson("/api/v1/admin/settlements/{$settlement->id}/pay", ['reference' => 'IP-1'])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->naqer->user_id, 'type' => 'settlement.collected']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->naqer->user_id, 'type' => 'settlement.paid']);
    }

    public function test_a_discount_the_place_bears_comes_off_what_it_is_owed(): void
    {
        PromoCode::query()->create([
            'code' => 'NAQER10', 'title_ar' => 'خصم الناقر', 'title_en' => 'Naqer', 'type' => 'fixed', 'value' => 10,
            'min_order_value' => 0, 'cost_bearer' => 'restaurant', 'is_active' => true,
        ]);

        $order = $this->order(200, 'vodafone_cash', null, 'NAQER10');
        $this->assertEqualsWithDelta(10.0, (float) $order->place_discount, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $order->commission_amount, 0.001, 'the commission is on the food before the code');

        $this->delivered($order);
        $this->assertEqualsWithDelta(180.0, (float) $this->settle()->net_amount, 0.001, '200 − 10 commission − 10 discount');
    }

    public function test_a_platform_discount_costs_the_place_nothing(): void
    {
        PromoCode::query()->create([
            'code' => 'CR10', 'title_ar' => 'خصم المنصة', 'title_en' => 'CR', 'type' => 'fixed', 'value' => 10,
            'min_order_value' => 0, 'cost_bearer' => 'platform', 'is_active' => true,
        ]);

        $order = $this->order(200, 'vodafone_cash', null, 'CR10');
        $this->assertEqualsWithDelta(0.0, (float) $order->place_discount, 0.001);

        $this->delivered($order);
        $this->assertEqualsWithDelta(190.0, (float) $this->settle()->net_amount, 0.001);
    }

    public function test_a_refund_is_charged_to_the_place_only_up_to_what_it_earned(): void
    {
        $order = $this->delivered($this->order(100, 'vodafone_cash'));
        $payment = Payment::query()->where('order_group_id', $order->order_group_id)->first();

        Refund::query()->create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => (float) $order->total,
            'type' => 'full', 'status' => 'processed', 'processed_at' => now(),
        ]);

        $settlement = $this->settle();
        $this->assertEqualsWithDelta(95.0, (float) $settlement->refunds_total, 0.001, 'not the delivery fee too');
        $this->assertEqualsWithDelta(0.0, (float) $settlement->net_amount, 0.001);
    }

    public function test_a_statements_days_are_cairo_days(): void
    {
        // 2026-11-07 00:30 Cairo = 2026-11-06 22:30 UTC — inside a period that starts 11-07.
        $early = $this->delivered($this->order(100, 'vodafone_cash'), Carbon::parse('2026-11-06 22:30:00', 'UTC'));
        // 2026-11-20 23:30 Cairo = 21:30 UTC — the period's last evening.
        $late = $this->delivered($this->order(100, 'vodafone_cash'), Carbon::parse('2026-11-20 21:30:00', 'UTC'));
        // 2026-11-21 00:30 Cairo — the next period.
        $next = $this->delivered($this->order(100, 'vodafone_cash'), Carbon::parse('2026-11-20 22:30:00', 'UTC'));

        $settlement = app(GenerateSettlementAction::class)->execute($this->admin(), 'restaurant', $this->naqer->id, '2026-11-07', '2026-11-20');

        $this->assertEqualsCanonicalizing([$early->id, $late->id], $settlement->items()->pluck('order_id')->all());
        $this->assertNull($next->fresh()->restaurant_settlement_id);
    }

    public function test_the_cycle_writes_one_draft_per_place_the_day_after_a_period_ends(): void
    {
        Settings::set('settlements.cycle_days', 14);
        Settings::set('settlements.cycle_start', '2026-11-07');

        $this->delivered($this->order(100), Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->delivered($this->order(200, 'vodafone_cash'), Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $after = $this->delivered($this->order(50), Carbon::parse('2026-11-21 12:00:00', 'UTC'));

        // The last day of the period: nothing yet.
        $this->travelTo(Carbon::parse('2026-11-20 20:00:00', 'UTC'));
        $this->artisan('cr7:settlement-cycle')->assertSuccessful();
        $this->assertSame(0, Settlement::query()->count());

        // The next morning, one draft, written by nobody.
        $this->travelTo(Carbon::parse('2026-11-21 08:00:00', 'UTC'));
        $this->artisan('cr7:settlement-cycle')->assertSuccessful();

        $settlement = Settlement::query()->sole();
        $this->assertSame('2026-11-07', $settlement->period_start->toDateString());
        $this->assertSame('2026-11-20', $settlement->period_end->toDateString());
        $this->assertNull($settlement->created_by);
        $this->assertTrue($settlement->breakdown['automatic']);
        $this->assertSame('draft', $settlement->status->value);
        $this->assertEqualsWithDelta(185.0, (float) $settlement->net_amount, 0.001);
        $this->assertNull($after->fresh()->restaurant_settlement_id, 'an order of the next period waits for it');

        // Running again writes nothing.
        $this->artisan('cr7:settlement-cycle')->assertSuccessful();
        $this->assertSame(1, Settlement::query()->count());
    }

    public function test_the_periods_follow_on_from_the_start_and_a_zero_cycle_is_off(): void
    {
        Settings::set('settlements.cycle_start', '2026-11-07');
        Settings::set('settlements.cycle_days', 14);

        $this->assertNull(SettlementCycleCommand::lastCompletedPeriod(Carbon::parse('2026-11-20 12:00', 'Africa/Cairo')));
        $this->assertSame(['2026-11-07', '2026-11-20'], SettlementCycleCommand::lastCompletedPeriod(Carbon::parse('2026-11-21 00:10', 'Africa/Cairo')));
        $this->assertSame(['2026-11-21', '2026-12-04'], SettlementCycleCommand::lastCompletedPeriod(Carbon::parse('2026-12-10 12:00', 'Africa/Cairo')));
        $this->assertSame(
            [['2026-11-07', '2026-11-20'], ['2026-11-21', '2026-12-04']],
            SettlementCycleCommand::completedPeriods(Carbon::parse('2026-12-10 12:00', 'Africa/Cairo')),
        );

        Settings::set('settlements.cycle_days', 0);
        $this->assertNull(SettlementCycleCommand::lastCompletedPeriod(Carbon::parse('2027-01-01', 'Africa/Cairo')));
    }

    public function test_the_place_and_the_office_read_the_same_balance_the_statement_writes(): void
    {
        $this->delivered($this->order(100));
        $this->delivered($this->order(200, 'vodafone_cash'));

        Sanctum::actingAs($this->naqer->owner);
        $summary = $this->withHeaders(['X-App' => 'restaurant'])->getJson('/api/v1/restaurant/reports/summary')->assertOk()->json('data');

        $this->assertEqualsWithDelta(300.0, $summary['gross'], 0.001);
        $this->assertEqualsWithDelta(15.0, $summary['commission'], 0.001);
        $this->assertEqualsWithDelta(285.0, $summary['net'], 0.001);
        $this->assertEqualsWithDelta(185.0, $summary['net_payable'], 0.001);
        $this->assertEqualsWithDelta(185.0, $summary['balance'], 0.001);
        $this->assertSame('platform_pays_place', $summary['balance_direction']);
        $this->assertSame(1, $summary['cash_orders_count']);
        $this->assertEqualsWithDelta(100.0, $summary['cash_food_total'], 0.001);
        $this->assertEqualsWithDelta(150.0, $summary['avg_order_value'], 0.001);

        Sanctum::actingAs($this->admin());
        $row = collect($this->withHeader('X-App', 'admin')->getJson('/api/v1/admin/finance/restaurants')->assertOk()->json('data'))
            ->firstWhere('restaurant_id', $this->naqer->id);
        $this->assertEqualsWithDelta(185.0, $row['balance'], 0.001);
        $this->assertEqualsWithDelta(5.0, $row['owed_by_place'], 0.001);

        $this->assertEqualsWithDelta(185.0, (float) $this->settle()->net_amount, 0.001);
    }

    // ------------------------------------------------------------------ helpers

    private function order(float $food, string $payment = 'cash', ?Restaurant $place = null, ?string $promo = null): Order
    {
        $place ??= $this->naqer;
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$place, $this->makeProduct($place, 'مندي '.uniqid(), $food), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => $payment] + ($promo === null ? [] : ['promo_code' => $promo]),
            'idem-'.uniqid('', true),
        );

        return Order::query()->where('order_group_id', $group->id)->sole();
    }

    private function delivered(Order $order, ?Carbon $at = null): Order
    {
        $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => $at ?? now()]);

        return $order->fresh();
    }

    private function settle(): Settlement
    {
        return app(GenerateSettlementAction::class)->execute(
            $this->admin(),
            'restaurant',
            $this->naqer->id,
            now()->subYear()->toDateString(),
            now()->addDay()->toDateString(),
        );
    }

    private ?User $admin = null;

    private function admin(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);

        $admin = User::query()->create([
            'name' => 'المشرف',
            'phone' => '010'.random_int(10000000, 99999999),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $this->admin = $admin;
    }
}
