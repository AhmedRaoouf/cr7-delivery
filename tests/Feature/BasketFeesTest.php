<?php

namespace Tests\Feature;

use App\Actions\Admin\AdminCancelOrderAction;
use App\Actions\Customer\CancelOrderGroupAction;
use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\RejectOrderAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\CheckoutPricer;
use App\Support\DeliveryCommission;
use App\Support\DriverEarningCalculator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 102 — a cancelled place hands its share of the basket's delivery and service fees to
 * the places still coming. CR7-2849: area fee 70, a second place +10, service 5; the second
 * place was cancelled and the customer paid 141.46 for 100 of food instead of 175.
 */
class BasketFeesTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    private Restaurant $first;

    private Restaurant $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(70.0);
        Settings::set('delivery.first_order_free', false);
        Settings::set('ring.enabled', false);

        $this->first = $this->makeRestaurant('كنج بروست');
        $this->second = $this->makeRestaurant('سندوتش عمر');
    }

    public function test_the_place_still_coming_carries_the_whole_delivery_and_service_fee(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]]);
        $this->assertEqualsWithDelta(330.0, (float) $first->orderGroup->total, 0.001, '245 + 80 + 5');

        app(RejectOrderAction::class)->execute($this->second->owner, $second, 'out_of_stock', null);

        $first = $first->fresh();
        $this->assertMoney(70.0, 5.0, 175.0, $first);
        $this->assertEqualsWithDelta(175.0, $first->cashToCollect(), 0.001, 'the captain collects 175, not 141.46');
        $this->assertMoney(0.0, 0.0, 145.0, $second->fresh());

        $group = $first->orderGroup->fresh();
        $this->assertEqualsWithDelta(70.0, (float) $group->delivery_fee, 0.001, 'one place, no extra stop');
        $this->assertEqualsWithDelta(320.0, (float) $group->total, 0.001);
        $this->assertEqualsWithDelta((float) $group->total, (float) Order::query()->where('order_group_id', $group->id)->sum('total'), 0.001);
    }

    public function test_the_captain_is_paid_on_the_whole_fee(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]]);
        $captain = $this->captain();
        $first->update([
            'status' => OrderStatus::DriverAssigned,
            'driver_id' => $captain->id,
            'driver_earning' => DriverEarningCalculator::calculate($first, $captain),
            'platform_delivery_margin' => DriverEarningCalculator::platformMargin($first, $captain),
        ]);

        app(AdminCancelOrderAction::class)->execute($this->admin(), $second, 'restaurant_timeout', null, false);

        $first = $first->fresh();
        $this->assertEqualsWithDelta(DeliveryCommission::driverShare(70.0), (float) $first->driver_earning, 0.001);
        $this->assertEqualsWithDelta(70.0, (float) $first->driver_earning + (float) $first->platform_delivery_margin, 0.001);
    }

    public function test_two_places_still_coming_share_the_fee_for_two(): void
    {
        $third = $this->makeRestaurant('ابن البلد');
        [$a, $b, $c] = $this->order([[$this->first, 100], [$this->second, 100], [$third, 50]]);

        app(RejectOrderAction::class)->execute($third->owner, $c, 'out_of_stock', null);

        $this->assertMoney(40.0, 2.5, 142.5, $a->fresh());
        $this->assertMoney(40.0, 2.5, 142.5, $b->fresh());
        $this->assertMoney(0.0, 0.0, 50.0, $c->fresh());
        $this->assertEqualsWithDelta(80.0, (float) $a->orderGroup->fresh()->delivery_fee, 0.001, '70 + one extra stop');
    }

    public function test_a_delivered_place_keeps_its_numbers(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]]);
        $before = $first->only(['delivery_fee_share', 'platform_fee_share', 'total']);
        $first->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

        app(AdminCancelOrderAction::class)->execute($this->admin(), $second, 'restaurant_timeout', null, false);

        $this->assertSame($before, $first->fresh()->only(['delivery_fee_share', 'platform_fee_share', 'total']));
        $this->assertSame('cash', $second->fresh()->payment_method->value);
    }

    public function test_a_free_delivery_stays_free(): void
    {
        Settings::set('delivery.first_order_free', true);
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]]);
        $this->assertSame('first_order', $first->free_delivery_reason);

        app(RejectOrderAction::class)->execute($this->second->owner, $second, 'out_of_stock', null);

        $this->assertMoney(0.0, 5.0, 105.0, $first->fresh());
    }

    public function test_a_basket_cancelled_whole_keeps_its_fees_where_they_were(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]]);
        $before = [$first->only(['delivery_fee_share', 'total']), $second->only(['delivery_fee_share', 'total'])];
        $groupTotal = (float) $first->orderGroup->total;

        app(CancelOrderGroupAction::class)->execute($first->orderGroup->user, $first->orderGroup, 'changed_mind', null);

        $this->assertSame($before, [$first->fresh()->only(['delivery_fee_share', 'total']), $second->fresh()->only(['delivery_fee_share', 'total'])]);
        $this->assertEqualsWithDelta($groupTotal, (float) $first->orderGroup->fresh()->total, 0.001);
    }

    public function test_a_basket_paid_by_transfer_refunds_the_food_and_the_stop_nobody_drives(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]], 'vodafone_cash');
        $group = $first->orderGroup;
        $group->payment()->update(['status' => 'paid', 'amount' => $group->total, 'paid_at' => now()]);

        app(AdminCancelOrderAction::class)->execute($this->admin(), $second, 'restaurant_timeout', null, true);

        $this->assertMoney(70.0, 5.0, 175.0, $first->fresh());
        $refund = (float) $second->refunds()->sum('amount');
        $this->assertEqualsWithDelta(155.0, $refund, 0.001, 'the food and the 10 extra stop');
        $this->assertEqualsWithDelta(175.0, 330.0 - $refund, 0.001, 'the customer ends up paying 175');
        $this->assertEqualsWithDelta(330.0, (float) $group->fresh()->total, 0.001, 'what was paid');
    }

    public function test_a_transfer_still_waiting_for_its_proof_asks_for_the_new_amount(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]], 'vodafone_cash');
        $group = $first->orderGroup;
        $group->payment()->update(['status' => 'awaiting_proof', 'amount' => $group->total]);

        app(RejectOrderAction::class)->execute($this->second->owner, $second, 'out_of_stock', null);

        $this->assertEqualsWithDelta(320.0, (float) $group->payment()->value('amount'), 0.001);
    }

    public function test_a_fee_carried_from_an_earlier_basket_moves_to_the_first_place_still_coming(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]]);
        $first->orderGroup->update(['outstanding_fees' => 20]);

        app(RejectOrderAction::class)->execute($this->first->owner, $first, 'out_of_stock', null);

        $this->assertEqualsWithDelta(0.0, $first->fresh()->carriedFees(), 0.001);
        $this->assertEqualsWithDelta(145.0 + 70.0 + 5.0 + 20.0, $second->fresh()->cashToCollect(), 0.001);
    }

    public function test_an_amendment_after_a_cancellation_prices_only_the_places_still_on_the_basket(): void
    {
        [$first, $second] = $this->order([[$this->first, 100], [$this->second, 145]]);
        app(RejectOrderAction::class)->execute($this->second->owner, $second, 'out_of_stock', null);

        $priced = app(CheckoutPricer::class)->repriceGroup(OrderGroup::query()->find($first->order_group_id), [$first->id => 120.0]);

        $this->assertEqualsWithDelta(70.0, $priced['orders'][$first->id]['delivery_fee_share'], 0.001);
        $this->assertEqualsWithDelta(5.0, $priced['orders'][$first->id]['platform_fee_share'], 0.001);
        $this->assertArrayNotHasKey($second->id, $priced['orders']);
        $this->assertEqualsWithDelta(120.0 + 145.0 + 70.0 + 5.0, $priced['group']['total'], 0.001);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array{0: Restaurant, 1: float}>  $lines
     * @return Collection<int, Order>
     */
    private function order(array $lines, string $payment = 'cash'): Collection
    {
        if (count($lines) > 2) {
            Settings::set('cart.max_restaurants', count($lines));
        }

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, array_map(fn (array $line) => [$line[0], $this->makeProduct($line[0], 'صنف '.$line[0]->name_ar, $line[1]), 1], $lines));

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => $payment],
            'idem-'.uniqid('', true),
        );

        return Order::query()->where('order_group_id', $group->id)->orderBy('group_sequence')->get();
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'المشرف',
            'phone' => '010'.random_int(10000000, 99999999),
            'type' => 'admin',
            'is_active' => true,
        ]);
    }

    private function assertMoney(float $delivery, float $service, float $total, Order $order): void
    {
        $this->assertEqualsWithDelta($delivery, (float) $order->delivery_fee_share, 0.001, "delivery on #{$order->id}");
        $this->assertEqualsWithDelta($service, (float) $order->platform_fee_share, 0.001, "service on #{$order->id}");
        $this->assertEqualsWithDelta($total, (float) $order->total, 0.001, "total on #{$order->id}");
    }
}
