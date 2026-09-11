<?php

namespace Tests\Feature;

use App\Actions\Admin\GenerateSettlementAction;
use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\QuoteDirectOrderAction;
use App\Http\Resources\Customer\OrderGroupCreatedResource;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PromoCode;
use App\Support\CheckoutPricer;
use App\Support\DriverEarningCalculator;
use App\Support\FirstOrderPerk;
use App\Support\RefundPolicy;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-63..D-65 — the platform service fee («رسوم الخدمة»).
 *
 * The load-bearing rule is #2: charged **once per order group**, never per restaurant.
 * The rest exist so nobody "tidies" the fee into the delivery fee, the discount, the
 * captain's earning or the restaurant's payout, all of which would be wrong in a way
 * that is invisible until somebody reconciles a month.
 */
class PlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();

        // Every customer this file makes is a brand-new account, and the platform's
        // welcome offer (`delivery.first_order_free`) would zero the delivery line on
        // all of them — hiding the numbers these tests exist to check. The offer has
        // its own file; here the customer is an ordinary returning one.
        Settings::set(FirstOrderPerk::SETTING, 0, 'delivery');
    }

    private function pricer(): CheckoutPricer
    {
        return app(CheckoutPricer::class);
    }

    public function test_a_single_restaurant_quote_carries_the_fee_and_the_total_includes_it(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش جمبري', 100);
        $cart = $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $quote = $this->pricer()->price($customer, $cart, $address, null, 'cash');

        $this->assertSame(5.0, $quote['platform_fee']);
        $this->assertSame(100.0, $quote['subtotal']);
        $this->assertSame(20.0, $quote['delivery_fee']);
        $this->assertSame(125.0, $quote['total']);
    }

    public function test_three_restaurants_pay_the_fee_once_and_the_shares_sum_back_to_it(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);

        $lines = [];
        foreach ([['أ', 100.0], ['ب', 60.0], ['ج', 40.0]] as [$name, $price]) {
            $restaurant = $this->makeRestaurant($name);
            $lines[] = [$restaurant, $this->makeProduct($restaurant, "صنف {$name}", $price), 1];
        }

        $cart = $this->makeCart($customer, $lines);
        $quote = $this->pricer()->price($customer, $cart, $address, null, 'cash');

        // Not 15. This is the whole point of the rule.
        $this->assertSame(5.0, $quote['platform_fee']);
        $this->assertCount(3, $quote['groups']);
        $this->assertSame(
            5.0,
            round(collect($quote['groups'])->sum('platform_fee_share'), 2),
            'the pro-rata shares must add back to the group fee exactly',
        );
    }

    public function test_a_percent_promo_does_not_discount_the_fee(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ابن البلد');
        $product = $this->makeProduct($restaurant, 'كشري', 100);
        $cart = $this->makeCart($customer, [[$restaurant, $product, 1]]);

        PromoCode::query()->create([
            'code' => 'TWENTY', 'title_ar' => 'خصم', 'title_en' => 'Discount',
            'type' => 'percent', 'value' => 20, 'is_active' => true,
        ]);

        $quote = $this->pricer()->price($customer, $cart, $address, 'TWENTY', 'cash');

        $this->assertSame(20.0, $quote['discount_amount']);
        $this->assertSame(5.0, $quote['platform_fee']);
        $this->assertSame(105.0, $quote['total']);   // 100 − 20 + 20 + 5
    }

    public function test_a_free_delivery_promo_zeroes_delivery_and_leaves_the_fee(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('كنج بروست');
        $product = $this->makeProduct($restaurant, 'زنجر', 120);
        $cart = $this->makeCart($customer, [[$restaurant, $product, 1]]);

        PromoCode::query()->create([
            'code' => 'FREEDEL', 'title_ar' => 'توصيل مجاني', 'title_en' => 'Free delivery',
            'type' => 'free_delivery', 'value' => 0, 'is_active' => true,
        ]);

        $quote = $this->pricer()->price($customer, $cart, $address, 'FREEDEL', 'cash');

        $this->assertSame(0.0, $quote['delivery_fee']);
        $this->assertSame(5.0, $quote['platform_fee']);
        $this->assertSame(125.0, $quote['total']);
    }

    public function test_free_delivery_over_a_threshold_does_not_waive_the_fee(): void
    {
        $this->zone->update(['free_delivery_over' => 100]);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('الركن العربي');
        $product = $this->makeProduct($restaurant, 'مندي', 150);
        $cart = $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $quote = $this->pricer()->price($customer, $cart->fresh(), $address->fresh(), null, 'cash');

        $this->assertTrue($quote['free_delivery_applied']);
        $this->assertSame(0.0, $quote['delivery_fee']);
        $this->assertSame(5.0, $quote['platform_fee']);
    }

    public function test_an_empty_cart_carries_no_fee(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $cart = $this->makeCart($customer, []);

        $quote = $this->pricer()->price($customer, $cart, $address, null, 'cash');

        // The zone's delivery fee is still quoted — the empty basket is refused by
        // the `cart_empty` blocker, not by pricing it at zero — but nothing is being
        // sold, so there is no service to charge for.
        $this->assertSame(0.0, $quote['platform_fee']);
        $this->assertContains('cart_empty', array_column($quote['blockers'], 'code'));
    }

    public function test_the_fee_is_snapshotted_so_changing_the_setting_does_not_reprice_a_placed_order(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('بوكس ع السريع');
        $product = $this->makeProduct($restaurant, 'برجر سنجل', 100);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $this->assertSame('5.00', (string) $group->platform_fee);
        $this->assertSame('125.00', (string) $group->total);

        Settings::set('platform.order_fee', 7.00);

        $this->assertSame('5.00', (string) $group->fresh()->platform_fee);
        $this->assertSame('125.00', (string) $group->fresh()->total);
    }

    public function test_the_sub_order_shares_add_back_to_the_group_total(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);

        $lines = [];
        foreach ([['أ', 90.0], ['ب', 70.0]] as [$name, $price]) {
            $restaurant = $this->makeRestaurant($name);
            $lines[] = [$restaurant, $this->makeProduct($restaurant, "صنف {$name}", $price), 1];
        }
        $this->makeCart($customer, $lines);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $orders = Order::query()->where('order_group_id', $group->id)->get();

        $this->assertSame(5.0, round((float) $orders->sum('platform_fee_share'), 2));
        $this->assertSame(
            (float) $group->total,
            round((float) $orders->sum('total'), 2),
            'the sub-order totals must reconstruct the group total exactly',
        );
    }

    public function test_the_captains_earning_never_includes_the_fee(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('صواني الدمياطي');
        $product = $this->makeProduct($restaurant, 'كفتة', 200);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();

        $driverUser = $this->makeCustomer();
        $driverUser->update(['type' => 'driver']);
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 100,
        ]);

        // 100% of the delivery fee share, and nothing at all of the service fee.
        $this->assertSame(
            (float) $order->delivery_fee_share,
            DriverEarningCalculator::calculate($order, $driver),
        );
    }

    public function test_a_platform_failure_refunds_the_fee_and_a_customer_cancellation_does_not(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('الركن العربي');
        $product = $this->makeProduct($restaurant, 'فتة لحمة', 200);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'instapay'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        $total = (float) $order->total;                  // 200 + 20 + 5

        // D-65 — the platform did not deliver the service it charged for.
        $order->update(['cancelled_by' => 'admin', 'cancellation_reason_code' => 'no_driver_found']);
        $this->assertTrue(RefundPolicy::feeIsRefundable($order->fresh()));
        $this->assertSame($total, RefundPolicy::fullRefundAmount($order->fresh()));

        // The customer changed their mind on an order already worked on.
        $order->update(['cancelled_by' => 'customer', 'cancellation_reason_code' => 'changed_my_mind']);
        $this->assertFalse(RefundPolicy::feeIsRefundable($order->fresh()));
        $this->assertSame($total - 5.0, RefundPolicy::fullRefundAmount($order->fresh()));
    }

    public function test_the_settlement_reports_the_fee_without_paying_it_out(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ابن البلد');
        $product = $this->makeProduct($restaurant, 'كشري لوكس', 100);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'instapay'],
            'idem-'.uniqid(),
        );

        Order::query()->where('order_group_id', $group->id)
            ->update(['status' => 'delivered', 'delivered_at' => now()]);

        $admin = $this->makeCustomer();
        $admin->update(['type' => 'admin']);

        $settlement = app(GenerateSettlementAction::class)->execute(
            $admin, 'restaurant', $restaurant->id, now()->toDateString(), now()->toDateString(),
        );

        $this->assertSame('5.00', (string) $settlement->platform_fee_total);
        // gross 100 − commission **0** (D-75, 2026-09-11) − refunds 0 − cash 0. The
        // service fee is reported and not deducted, which is the point of this test; the
        // commission line is now always zero because the platform charges none.
        $this->assertSame('0.00', (string) $settlement->commission_total);
        $this->assertSame('100.00', (string) $settlement->net_amount);
        $this->assertSame('5.00', (string) $settlement->items->first()->platform_fee);
        $this->assertSame('100.00', (string) $settlement->items->first()->net);
    }

    public function test_the_config_endpoint_publishes_the_amount(): void
    {
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.platform.order_fee', 5)
            ->assertJsonPath('data.platform.order_fee_label_ar', 'رسوم الخدمة');
    }

    public function test_a_direct_order_carries_the_fee_and_the_setting_turns_it_off(): void
    {
        $restaurant = $this->makeRestaurant('ناصر القياس');
        $restaurant->servedZones()->attach($this->zone->id);

        $quote = app(QuoteDirectOrderAction::class)
            ->execute($restaurant->fresh(), 31.3835, 31.4835);

        $this->assertSame(5.0, $quote['platform_fee']);
        $this->assertSame(25.0, $quote['total']);      // 20 delivery + 5 service

        Settings::set('platform.order_fee_applies_to_direct', false);

        $quote = app(QuoteDirectOrderAction::class)
            ->execute($restaurant->fresh(), 31.3835, 31.4835);

        $this->assertSame(0.0, $quote['platform_fee']);
        $this->assertSame(20.0, $quote['total']);
    }

    public function test_the_created_order_resource_shows_the_fee_as_its_own_line(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('سعد الدمياطي');
        $product = $this->makeProduct($restaurant, 'فطيرة', 150);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $payload = (new OrderGroupCreatedResource(
            OrderGroup::query()->with(['orders.restaurant', 'payment'])->find($group->id),
        ))->toArray(request());

        $this->assertSame(5.0, $payload['platform_fee']);
        $this->assertSame(5.0, (float) $payload['sub_orders'][0]['platform_fee_share']);
        // D-44 — Modern Standard Arabic, never colloquial.
        $this->assertStringNotContainsString('بيتحدد', $payload['payment']['note']);
    }
}
