<?php

namespace Tests\Feature;

use App\Actions\Customer\CancelOrderGroupAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Refund;
use App\Models\User;
use App\Support\CheckoutPricer;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «الغاء الطلب ينفع طالما المطعم مبدأش تحضير، ولو عايز يلغي بعد كدا لازم يحول
 * رسوم عشرين جنيه» — operator, 2026-09-09.
 *
 * doc 04 §1 used to stop the customer's cancel button at the kitchen door. It
 * now reaches one state past it and costs `orders.late_cancellation_fee` there.
 * Three things have to hold together or the rule is a trap rather than a
 * policy: the button appears exactly where the server will honour it, the fee
 * is charged once for the whole basket, and the money it comes out of is real.
 */
class LateCancellationFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('orders.late_cancellation_fee', 20.00, 'orders');
    }

    /** @param  list<OrderStatus>  $statuses  one per sub-order */
    private function makeGroup(User $customer, array $statuses, string $paymentMethod = 'cash', float $total = 120): OrderGroup
    {
        $group = OrderGroup::query()->create([
            'user_id' => $customer->id,
            'group_number' => 'G-'.random_int(1000, 9999),
            'idempotency_key' => (string) Str::uuid(),
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => $paymentMethod,
            'restaurant_count' => count($statuses),
            'subtotal' => 100,
            'delivery_fee' => 20,
            'platform_fee' => 0,
            'total' => $total,
            'rollup_status' => 'preparing',
        ]);

        foreach ($statuses as $index => $status) {
            Order::query()->create([
                'order_group_id' => $group->id,
                'user_id' => $customer->id,
                'restaurant_id' => $this->makeRestaurant('مكان '.$index)->id,
                'city_id' => $this->city->id,
                'service_zone_id' => $this->zone->id,
                'order_number' => 'O-'.random_int(10000, 99999),
                'idempotency_key' => (string) Str::uuid(),
                'group_sequence' => $index + 1,
                'status' => $status->value,
                'payment_method' => $paymentMethod,
                'restaurant_name_snapshot' => 'مكان '.$index,
                'customer_name_snapshot' => 'أحمد',
                'delivery_address_text' => 'شارع البحر',
                'delivery_contact_phone' => $customer->phone,
                'subtotal' => 100,
                'delivery_fee_share' => 20,
                'platform_fee_share' => 0,
                'total' => $total,
            ]);
        }

        return $group->fresh();
    }

    private function cancel(User $customer, OrderGroup $group): array
    {
        return app(CancelOrderGroupAction::class)->execute($customer, $group, 'changed_mind', null);
    }

    public function test_the_line_moved_by_exactly_one_state(): void
    {
        // The whole rule, read off the enum the action and the resource share.
        foreach ([
            OrderStatus::Pending, OrderStatus::RestaurantTimeout, OrderStatus::Accepted,
            OrderStatus::AwaitingCustomerConfirmation, OrderStatus::AwaitingPayment,
            OrderStatus::Confirmed,
        ] as $free) {
            $this->assertTrue($free->customerCanCancel(), $free->value);
            $this->assertTrue($free->customerCancelIsFree(), $free->value);
        }

        $this->assertTrue(OrderStatus::Preparing->customerCanCancel());
        $this->assertFalse(OrderStatus::Preparing->customerCancelIsFree());

        // `ready` is the wall — the food is packed and a captain is being found.
        foreach ([
            OrderStatus::Ready, OrderStatus::SearchingDriver, OrderStatus::DriverAssigned,
            OrderStatus::PickedUp, OrderStatus::OnTheWay, OrderStatus::Delivered,
        ] as $tooLate) {
            $this->assertFalse($tooLate->customerCanCancel(), $tooLate->value);
        }
    }

    public function test_confirmed_is_free_and_used_to_be_refused_outright(): void
    {
        // The app has always drawn the cancel button in `confirmed`; the server
        // answered `order_not_cancellable`. That was a button that lied.
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Confirmed]);

        $result = $this->cancel($customer, $group);

        $this->assertCount(1, $result['cancelled']);
        $this->assertSame(0.0, $result['fee']);
        $this->assertSame(0.0, (float) $customer->fresh()->outstanding_fees);
    }

    public function test_cancelling_a_kitchen_that_started_costs_the_fee(): void
    {
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Preparing]);

        $result = $this->cancel($customer, $group);

        $this->assertSame(20.0, $result['fee']);
        $this->assertSame(20.0, (float) Order::query()->sum('cancellation_fee'));
        // Cash paid nothing, so there is no refund to take it out of and the
        // balance waits for the next basket.
        $this->assertSame(20.0, (float) $customer->fresh()->outstanding_fees);
    }

    public function test_the_fee_is_charged_once_for_the_whole_basket(): void
    {
        // Three kitchens, one decision. Charging per sub-order would turn the
        // 20 the app promised into a 60 the customer never agreed to.
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [
            OrderStatus::Preparing, OrderStatus::Preparing, OrderStatus::Preparing,
        ]);

        $result = $this->cancel($customer, $group);

        $this->assertSame(20.0, $result['fee']);
        $this->assertSame(20.0, (float) Order::query()->sum('cancellation_fee'));
        $this->assertSame(20.0, (float) $customer->fresh()->outstanding_fees);
    }

    public function test_a_basket_only_partly_cooking_still_pays_once(): void
    {
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Pending, OrderStatus::Preparing]);

        $this->assertSame(20.0, $this->cancel($customer, $group)['fee']);
    }

    public function test_a_basket_nobody_has_started_is_still_free(): void
    {
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Pending, OrderStatus::Accepted]);

        $this->assertSame(0.0, $this->cancel($customer, $group)['fee']);
        $this->assertSame(0.0, (float) $customer->fresh()->outstanding_fees);
    }

    public function test_a_paid_basket_has_the_fee_taken_out_of_the_refund(): void
    {
        // The operator's chosen mechanism: «خصم من المبلغ المسترد».
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Preparing], 'vodafone_cash', 120);
        $group->payment()->create([
            'user_id' => $customer->id,
            'method' => 'vodafone_cash',
            'provider' => 'manual',
            'status' => 'paid',
            'amount' => 120,
        ]);

        $result = $this->cancel($customer, $group->fresh());

        $this->assertSame(20.0, $result['fee']);
        $refund = Refund::query()->sole();
        $this->assertSame(100.0, (float) $refund->amount);
        $this->assertSame('partial', $refund->type->value);
        // Netted, so nothing is left on the account to chase.
        $this->assertSame(0.0, (float) $customer->fresh()->outstanding_fees);
    }

    public function test_a_refund_smaller_than_the_fee_never_goes_negative(): void
    {
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Preparing], 'instapay', 8);
        $group->payment()->create([
            'user_id' => $customer->id,
            'method' => 'instapay',
            'provider' => 'manual',
            'status' => 'paid',
            'amount' => 8,
        ]);

        $this->cancel($customer, $group->fresh());

        $this->assertSame(0.0, (float) Refund::query()->sole()->amount);
    }

    public function test_the_balance_is_collected_by_the_next_basket(): void
    {
        $customer = $this->makeCustomer();
        $customer->forceFill(['outstanding_fees' => 20])->save();

        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش جمبري', 100);
        $cart = $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, null, 'cash');

        $this->assertSame(20.0, $quote['outstanding_fees']);
        // Its own line, added after every discount: a promo code is a reason to
        // charge less for food, not a reason to forgive a fee.
        $this->assertSame(
            $quote['subtotal'] - $quote['discount_amount'] + $quote['delivery_fee'] + $quote['platform_fee'] + 20.0,
            $quote['total'],
        );
    }

    public function test_a_zero_setting_makes_a_late_cancellation_free_again(): void
    {
        // The knob is the point: `preparing` stays cancellable either way.
        Settings::set('orders.late_cancellation_fee', 0, 'orders');

        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Preparing]);

        $result = $this->cancel($customer, $group);

        $this->assertCount(1, $result['cancelled']);
        $this->assertSame(0.0, $result['fee']);
        $this->assertSame(0.0, (float) $customer->fresh()->outstanding_fees);
    }

    public function test_a_basket_past_ready_is_still_refused(): void
    {
        $customer = $this->makeCustomer();
        $group = $this->makeGroup($customer, [OrderStatus::Ready]);

        $this->expectExceptionMessage('لا يمكن إلغاء الطلب في مرحلته الحالية');
        $this->cancel($customer, $group);
    }
}
