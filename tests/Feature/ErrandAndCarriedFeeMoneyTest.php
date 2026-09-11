<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Errand\ErrandLifecycleAction;
use App\Enums\ErrandStatus;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\User;
use App\Support\DriverLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-75 — «‪15‬٪ على **أي** order من فلوس الديليفري».
 *
 * An errand's fee is delivery money the customer hands the captain, and until now it was
 * the one delivery in the platform that moved no money at all: no wallet credit, no cash
 * row, no margin. A captain could ride errands all day and the ledger would say they had
 * earned nothing and owed nothing.
 */
class ErrandAndCarriedFeeMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_errand_pays_the_captain_and_leaves_the_platform_its_fifteen_percent(): void
    {
        $this->seedLocation();

        $customer = $this->makeCustomer();
        $captain = $this->makeCaptain();

        $errand = ErrandRequest::query()->create([
            'reference' => 'ER-1001',
            'user_id' => $customer->id,
            'driver_id' => $captain->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => ErrandStatus::PickedUp,
            'pickup_address_text' => 'صيدلية جمصة',
            'pickup_lat' => 31.384,
            'pickup_lng' => 31.484,
            'dropoff_address_text' => 'شارع البحر',
            'dropoff_lat' => 31.386,
            'dropoff_lng' => 31.486,
            'details' => 'دواء',
            'distance_km' => 2.1,
            'offered_fee' => 100,
            'picked_up_at' => now(),
        ]);

        app(ErrandLifecycleAction::class)->markDelivered($captain, $errand);

        $fresh = $errand->fresh();
        $this->assertSame('85.00', (string) $fresh->driver_earning);
        $this->assertSame('15.00', (string) $fresh->platform_margin);

        $balances = DriverLedger::balances($captain->fresh());
        $this->assertSame(85.0, $balances['wallet_balance'], 'the captain keeps 85 %');
        $this->assertSame(100.0, $balances['cash_in_hand'], 'and is carrying the whole fee');
        $this->assertSame(15.0, $balances['net_due_to_platform'], 'so the office is owed the 15 %');
    }

    /**
     * D-74's cash path, from the captain's side.
     *
     * A late-cancellation fee on cash is carried onto the next basket, where
     * `CheckoutPricer` adds it to the group's total and the customer's receipt shows it.
     * The captain's card was built from the **sub-order** total, which does not contain
     * it — so the app asked for ‪20‬ ج.م nobody had told the captain to collect.
     */
    public function test_the_captain_collects_the_balance_carried_from_a_late_cancellation(): void
    {
        $this->seedLocation();

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ابن البلد');
        $product = $this->makeProduct($restaurant, 'كشري', 100);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $customer->forceFill(['outstanding_fees' => 20])->save();

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $this->assertSame('20.00', (string) $group->outstanding_fees);

        $order = $group->orders()->firstOrFail();

        $this->assertSame(
            round((float) $order->total + 20, 2),
            $order->cashToCollect(),
            'what the customer is asked for is what the captain is told to collect',
        );
    }

    private function makeCaptain(): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => 'كابتن مشاوير']);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
        ]);
    }
}
