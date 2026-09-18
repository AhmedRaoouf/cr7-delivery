<?php

namespace Tests\Feature;

use App\Actions\Customer\ReleaseScheduledOrdersAction;
use App\Enums\AdminScopeType;
use App\Models\OrderGroup;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\ScheduledOrder;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\CashLimit;
use App\Support\FirstOrderPerk;
use App\Support\Settings;
use App\Support\SettingsCatalog;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Operator, 2026-09-18: «عايز لو الاوردر فوق الالف جنيه لازم تحويل فودافون كاش او انستا باي».
 *
 * Every door a customer's basket can be paid through, checked against
 * `payment.cash_max_total`: the quote, placing, scheduling (and its release), and
 * «ادفع نقدًا بدلًا من ذلك» on a transfer already under way. The amount is always the
 * group's `total` as the pricer computes it, and «فوق الألف» is strictly above.
 *
 * The fixture's arithmetic: one place, a delivery fee of ‪20‬ and a service fee of ‪5‬, the
 * first-order perk off — so a single product at ‪975‬ is a total of exactly ‪1000‬.
 */
class CashLimitTest extends TestCase
{
    use RefreshDatabase;

    private const AR_REFUSAL = "الدفع نقدًا متاح للطلبات حتى \u{202A}1,000\u{202C} ج.م — اختر فودافون كاش أو إنستا باي";

    private User $customer;

    private UserAddress $address;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(deliveryFee: 20.0);

        Settings::set(FirstOrderPerk::SETTING, false, 'delivery');
        Settings::set('payment.vodafone_cash_number', '01099450110', 'payment');
        Settings::set('payment.instapay_handle', 'cr7delivery@instapay', 'payment');

        $this->customer = $this->makeCustomer();
        $this->address = $this->makeAddress($this->customer);
        $this->place = $this->makeRestaurant('سعد الدمياطي');

        $this->asCustomer();
    }

    // ------------------------------------------------------------------ the rule

    public function test_the_default_limit_is_one_thousand(): void
    {
        $this->assertSame(1000.0, CashLimit::max());
        $this->assertTrue(CashLimit::allows(1000));
        $this->assertFalse(CashLimit::allows(1000.01));
    }

    public function test_cash_at_exactly_the_limit_is_taken(): void
    {
        $this->basketOf(975);

        $quote = $this->quote('cash')->assertOk();
        $this->assertEquals(1000, $quote->json('data.total'));
        $this->assertSame([], $quote->json('data.blockers'));

        $this->place('cash', 'at-the-limit')->assertCreated();

        $group = OrderGroup::query()->sole();
        $this->assertSame('cash', $group->payment_method->value);
        $this->assertEquals(1000, (float) $group->total);
    }

    public function test_cash_one_piastre_above_the_limit_is_refused_by_name_with_the_numbers(): void
    {
        $this->basketOf(975.01);

        $quote = $this->quote('cash')->assertOk();
        $this->assertEquals(1000.01, $quote->json('data.total'));
        $this->assertSame('cash_not_allowed', $quote->json('data.blockers.0.code'));
        $this->assertSame(self::AR_REFUSAL, $quote->json('data.blockers.0.message'));
        $this->assertEquals(1000, $quote->json('data.blockers.0.cash_max_total'));
        $this->assertEquals(1000.01, $quote->json('data.blockers.0.total'));

        $this->place('cash', 'above-the-limit')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'cash_not_allowed')
            ->assertJsonPath('message', self::AR_REFUSAL)
            ->assertJsonPath('meta.cash_max_total', 1000)
            ->assertJsonPath('meta.total', 1000.01)
            ->assertJsonPath('meta.blockers.0.code', 'cash_not_allowed');

        $this->assertSame(0, OrderGroup::query()->count());
    }

    public function test_either_transfer_above_the_limit_is_taken(): void
    {
        foreach (['vodafone_cash', 'instapay'] as $method) {
            $this->basketOf(1500);

            $this->quote($method)->assertOk()->assertJsonPath('data.blockers', []);
            $this->place($method, "transfer-{$method}")->assertCreated();
        }

        $this->assertSame(
            ['vodafone_cash', 'instapay'],
            OrderGroup::query()->orderBy('id')->get()->map(fn ($g) => $g->payment_method->value)->all(),
        );
    }

    public function test_a_limit_of_zero_is_no_limit(): void
    {
        Settings::set(CashLimit::SETTING, 0, 'payment');
        $this->basketOf(4975);

        $quote = $this->quote('cash')->assertOk();
        $this->assertSame([], $quote->json('data.blockers'));
        $this->assertEquals(0, $quote->json('data.cash_max_total'));
        $this->assertTrue(collect($quote->json('data.payment_methods'))->every(fn ($m) => $m['available'] === true));

        $this->place('cash', 'no-limit')->assertCreated();
    }

    public function test_a_basket_of_two_places_is_judged_on_the_group_total(): void
    {
        // Each place alone is well under the limit; the one payment is not:
        // 490 + 490 + delivery 20 + extra stop 10 + service 5 = 1015.
        $second = $this->makeRestaurant('كنج بروست');
        $this->makeCart($this->customer, [
            [$this->place, $this->makeProduct($this->place, 'صينية', 490), 1],
            [$second, $this->makeProduct($second, 'وجبة', 490), 1],
        ]);

        $quote = $this->quote('cash')->assertOk();
        $this->assertSame(2, $quote->json('data.restaurant_count'));
        $this->assertEquals(1015, $quote->json('data.total'));
        $this->assertSame('cash_not_allowed', $quote->json('data.blockers.0.code'));

        $this->place('cash', 'two-places')
            ->assertStatus(422)
            ->assertJsonPath('error', 'cash_not_allowed')
            ->assertJsonPath('meta.total', 1015);

        $this->place('vodafone_cash', 'two-places-transfer')->assertCreated();
        $this->assertSame(2, OrderGroup::query()->sole()->orders()->count());
    }

    public function test_a_promo_that_brings_the_total_under_the_limit_lets_cash_through(): void
    {
        PromoCode::query()->create([
            'code' => 'FIFTY', 'title_ar' => 'خصم', 'title_en' => 'Discount',
            'type' => 'fixed', 'value' => 50, 'is_active' => true,
        ]);

        // 1000 + 20 + 5 = 1025 without the code, 975 with it.
        $this->basketOf(1000);

        $this->quote('cash')->assertOk()->assertJsonPath('data.blockers.0.code', 'cash_not_allowed');

        $withCode = $this->quote('cash', 'FIFTY')->assertOk();
        $this->assertEquals(975, $withCode->json('data.total'));
        $this->assertSame([], $withCode->json('data.blockers'));
        $this->assertTrue(collect($withCode->json('data.payment_methods'))->firstWhere('value', 'cash')['available']);

        $this->place('cash', 'with-code', 'FIFTY')->assertCreated();
        $this->assertEquals(975, (float) OrderGroup::query()->sole()->total);
    }

    public function test_a_carried_late_cancellation_fee_counts_toward_the_total(): void
    {
        // What the customer hands over is the whole total — a fee collected by this
        // basket is part of it.
        $this->customer->forceFill(['outstanding_fees' => 20])->save();
        $this->basketOf(975);

        $quote = $this->quote('cash')->assertOk();
        $this->assertEquals(1020, $quote->json('data.total'));
        $this->assertSame('cash_not_allowed', $quote->json('data.blockers.0.code'));
    }

    // -------------------------------------------------------------- scheduling

    public function test_a_scheduled_order_follows_the_same_rule(): void
    {
        $this->basketOf(1200);

        $this->schedule('cash')
            ->assertStatus(422)
            ->assertJsonPath('error', 'cash_not_allowed')
            ->assertJsonPath('message', self::AR_REFUSAL)
            ->assertJsonPath('meta.cash_max_total', 1000)
            ->assertJsonPath('meta.total', 1225);

        $this->assertSame(0, ScheduledOrder::query()->count());

        $this->schedule('instapay')->assertCreated();
    }

    public function test_a_scheduled_cash_order_is_judged_again_when_it_is_released(): void
    {
        // Taken at 1000 when it was scheduled; the limit is lowered before its time.
        $this->basketOf(975);
        $this->schedule('cash')->assertCreated();

        Settings::set(CashLimit::SETTING, 500, 'payment');
        Carbon::setTestNow(now()->addHours(4));
        $result = app(ReleaseScheduledOrdersAction::class)->releaseDue();
        Carbon::setTestNow();

        $this->assertSame(['released' => 0, 'failed' => 1], $result);
        $row = ScheduledOrder::query()->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame('cash_not_allowed', $row->failure_code);
        $this->assertSame(0, OrderGroup::query()->count());
    }

    // ------------------------------------------------------ what the quote says

    public function test_the_quote_says_which_methods_this_basket_may_use(): void
    {
        $this->basketOf(1500);

        // Asked for a transfer, the quote still says cash is out for this basket.
        $quote = $this->quote('vodafone_cash')->assertOk();
        $methods = collect($quote->json('data.payment_methods'))->keyBy('value');

        $this->assertEquals(1000, $quote->json('data.cash_max_total'));
        $this->assertSame(['cash', 'vodafone_cash', 'instapay'], $methods->keys()->all());

        $this->assertFalse($methods['cash']['available']);
        $this->assertSame('above_cash_limit', $methods['cash']['reason']);
        // `enabled` keeps its D-109 meaning — switched on at ADM-46 — for the installs
        // that filter the picker on it.
        $this->assertTrue($methods['cash']['enabled']);

        foreach (['vodafone_cash', 'instapay'] as $method) {
            $this->assertTrue($methods[$method]['available']);
            $this->assertNull($methods[$method]['reason']);
        }

        // The destinations are still there, unchanged.
        $this->assertSame('01099450110', $methods['vodafone_cash']['number']);
        $this->assertSame('cr7delivery@instapay', $methods['instapay']['handle']);

        // Under the limit every row is available.
        $this->basketOf(100);
        $small = collect($this->quote('cash')->assertOk()->json('data.payment_methods'));
        $this->assertTrue($small->every(fn ($m) => $m['available'] === true && $m['reason'] === null));
    }

    public function test_an_account_held_to_cash_is_told_the_only_way_left(): void
    {
        $this->customer->forceFill(['cash_only_until' => now()->addDays(3)])->save();
        $this->basketOf(1500);

        $quote = $this->quote('cash')->assertOk();
        $methods = collect($quote->json('data.payment_methods'))->keyBy('value');

        $this->assertSame('above_cash_limit', $methods['cash']['reason']);
        $this->assertSame('cash_only_restricted', $methods['vodafone_cash']['reason']);
        $this->assertSame('cash_only_restricted', $methods['instapay']['reason']);

        // Not «اختر فودافون كاش» to someone the checkout will refuse it to.
        $this->assertSame(
            "الدفع نقدًا متاح للطلبات حتى \u{202A}1,000\u{202C} ج.م — قلّل محتوى السلة لإتمام الطلب",
            $quote->json('data.blockers.0.message'),
        );
    }

    public function test_the_sentence_names_only_the_transfers_that_are_on_and_follows_the_language(): void
    {
        Settings::set('payment.instapay_enabled', false, 'payment');
        Settings::set(CashLimit::SETTING, 1250.5, 'payment');
        $this->basketOf(1300);

        $this->quote('cash')->assertOk()->assertJsonPath(
            'data.blockers.0.message',
            "الدفع نقدًا متاح للطلبات حتى \u{202A}1,250.50\u{202C} ج.م — اختر فودافون كاش",
        );

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/orders', ['address_id' => $this->address->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'english'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cash is available for orders up to 1,250.50 EGP — choose Vodafone Cash');
    }

    // ------------------------------------------ «ادفع نقدًا بدلًا من ذلك»

    public function test_switching_a_transfer_to_cash_is_refused_above_the_limit(): void
    {
        $this->basketOf(1200);
        $group = $this->placeWithOpenGate('vodafone_cash', 'switch-above');

        $this->getJson("/api/v1/orders/{$group->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.can_switch_to_cash', false)
            ->assertJsonPath('data.cash_max_total', 1000);

        $this->postJson("/api/v1/orders/{$group->id}/payment/method")
            ->assertStatus(422)
            ->assertJsonPath('error', 'cash_not_allowed')
            ->assertJsonPath('message', "الدفع نقدًا متاح للطلبات حتى \u{202A}1,000\u{202C} ج.م — أكمل التحويل لإتمام طلبك")
            ->assertJsonPath('meta.cash_max_total', 1000)
            ->assertJsonPath('meta.total', 1225);

        $this->assertSame('vodafone_cash', $group->fresh()->payment_method->value);
        $this->assertSame('awaiting_proof', $group->fresh()->payment->status->value);
    }

    public function test_switching_a_transfer_to_cash_within_the_limit_still_works(): void
    {
        $this->basketOf(500);
        $group = $this->placeWithOpenGate('instapay', 'switch-within');

        $this->getJson("/api/v1/orders/{$group->id}/payment")->assertOk()->assertJsonPath('data.can_switch_to_cash', true);
        $this->postJson("/api/v1/orders/{$group->id}/payment/method")->assertOk();

        $this->assertSame('cash', $group->fresh()->payment_method->value);
    }

    // ------------------------------------------------------ a direct order

    public function test_a_direct_order_is_not_a_basket_and_is_never_capped(): void
    {
        // The place's own phone order: the captain collects the food and the fee in cash
        // at the door, whatever it comes to.
        Settings::set(CashLimit::SETTING, 100, 'payment');
        $this->place->forceFill(['direct_orders_enabled' => true])->save();

        Sanctum::actingAs(User::query()->findOrFail($this->place->user_id));

        $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/restaurant/direct-orders', [
                'guest_customer_name' => 'محمد',
                'guest_customer_phone' => '01012345678',
                'delivery_address_text' => 'شارع البحر',
                'delivery_latitude' => 31.3835,
                'delivery_longitude' => 31.4835,
                'zone_id' => $this->zone->id,
                'restaurant_order_ref' => 'A-17',
                'declared_order_value' => 3000,
                'food_payment' => 'collect_by_driver',
                'ready_now' => true,
            ], ['Idempotency-Key' => 'direct-above'])
            ->assertCreated()
            ->assertJsonPath('data.origin', 'restaurant_direct');
    }

    // ------------------------------------------------------------ the panel

    public function test_a_limit_with_both_transfers_off_is_refused(): void
    {
        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [
            CashLimit::SETTING => 0,
            'payment.vodafone_cash_enabled' => false,
            'payment.instapay_enabled' => false,
        ]])->assertOk();

        // A limit now would leave an order above it with nothing to pay by.
        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [CashLimit::SETTING => 1000]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['errors' => [CashLimit::SETTING]]);

        $this->assertSame(0.0, CashLimit::max());

        // Turning a transfer back on in the same edit makes it sane.
        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [
            CashLimit::SETTING => 1000,
            'payment.instapay_enabled' => true,
        ]])->assertOk();

        $this->assertSame(1000.0, CashLimit::max());
    }

    public function test_the_last_transfer_cannot_be_switched_off_while_a_limit_stands(): void
    {
        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => ['payment.vodafone_cash_enabled' => false]])
            ->assertOk();

        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => ['payment.instapay_enabled' => false]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['payment.instapay_enabled']]);

        $this->assertTrue(SettingsCatalog::bool('payment.instapay_enabled'));

        // With the limit lifted it may go — cash is still on, so something remains.
        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [
            CashLimit::SETTING => 0,
            'payment.instapay_enabled' => false,
        ]])->assertOk();
    }

    public function test_the_limit_is_a_bounded_number(): void
    {
        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [CashLimit::SETTING => -1]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => [CashLimit::SETTING]]);

        $field = SettingsCatalog::field(CashLimit::SETTING);
        $this->assertSame('payment', $field['group']);
        $this->assertTrue($field['public']);
        $this->assertSame(1000, $field['default']);
    }

    // ----------------------------------------------------------- GET /config

    public function test_a_saved_limit_reaches_config_at_once(): void
    {
        $this->app['auth']->forgetGuards();

        $first = $this->getJson('/api/v1/config')->assertOk();
        $this->assertEquals(1000, $first->json('data.payment.cash_max_total'));
        $this->assertSame('HIT', $this->getJson('/api/v1/config')->headers->get('X-Cache'));

        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [CashLimit::SETTING => 1500]])->assertOk();
        $this->app['auth']->forgetGuards();

        $after = $this->withHeaders(['X-App' => 'app'])->getJson('/api/v1/config')->assertOk();
        $this->assertSame('MISS', $after->headers->get('X-Cache'));
        $this->assertEquals(1500, $after->json('data.payment.cash_max_total'));

        // Zero is a value, and it is published as one (D-131).
        $this->asAdmin()->patchJson('/api/v1/admin/settings', ['settings' => [CashLimit::SETTING => 0]])->assertOk();
        $this->app['auth']->forgetGuards();

        $zero = $this->getJson('/api/v1/config')->json('data.payment.cash_max_total');
        $this->assertNotNull($zero);
        $this->assertEquals(0, $zero);
    }

    // ---------------------------------------------------------------- helpers

    private function asCustomer(): void
    {
        Sanctum::actingAs($this->customer);
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function asAdmin(): static
    {
        if (! User::query()->where('type', 'admin')->exists()) {
            $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        }

        $admin = User::query()->where('type', 'admin')->first() ?? tap(User::factory()->create(['type' => 'admin']), function (User $admin) {
            $admin->assignRole('super_admin');
            $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        });

        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    /** One product at [price], alone in the customer's basket. */
    private function basketOf(float $price): void
    {
        $this->customer->cart()->each(function ($cart) {
            $cart->items()->delete();
            $cart->forceDelete();
        });

        $this->makeCart($this->customer, [[$this->place, $this->makeProduct($this->place, 'صينية '.$price, $price), 1]]);
    }

    private function quote(string $method, ?string $promo = null): TestResponse
    {
        return $this->postJson('/api/v1/checkout/quote', array_filter([
            'address_id' => $this->address->id,
            'payment_method' => $method,
            'promo_code' => $promo,
        ]));
    }

    private function place(string $method, string $key, ?string $promo = null): TestResponse
    {
        return $this->postJson('/api/v1/orders', array_filter([
            'address_id' => $this->address->id,
            'payment_method' => $method,
            'promo_code' => $promo,
        ]), ['Idempotency-Key' => $key]);
    }

    private function schedule(string $method): TestResponse
    {
        return $this->postJson('/api/v1/scheduled-orders', [
            'address_id' => $this->address->id,
            'payment_method' => $method,
            'scheduled_for' => now()->addHours(3)->toIso8601String(),
        ]);
    }

    /** Placed through the route; the answers and the confirmation written as their actions write them. */
    private function placeWithOpenGate(string $method, string $key): OrderGroup
    {
        $this->place($method, $key)->assertCreated();

        $group = OrderGroup::query()->latest('id')->firstOrFail();
        $group->orders()->update(['status' => 'awaiting_payment']);
        $group->payment()->update([
            'status' => 'awaiting_proof',
            'amount' => $group->total,
            'gate_opened_at' => now(),
            'deadline_at' => now()->addMinutes(15),
        ]);

        return $group->fresh();
    }
}
