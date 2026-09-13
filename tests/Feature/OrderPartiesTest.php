<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\QuoteDirectOrderAction;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-101 — everyone on an order sees everyone on it; D-102 — the fee is the area's price.
 */
class OrderPartiesTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private User $customer;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);

        $this->customer = $this->makeCustomer();
        $address = $this->makeAddress($this->customer);
        $address->update(['building' => '12', 'floor' => '3', 'apartment' => '5', 'landmark' => 'بجوار المسجد']);
        $restaurant = $this->makeRestaurant('كنج بروست');
        $this->makeCart($this->customer, [[$restaurant, $this->makeProduct($restaurant, 'بروست', 90), 1]]);

        $group = app(CreateOrderAction::class)->execute($this->customer, ['address_id' => $address->id, 'payment_method' => 'cash'], 'idem-'.uniqid());
        $this->order = Order::query()->where('order_group_id', $group->id)->firstOrFail();

        $captain = User::query()->create(['name' => 'محمود علي', 'phone' => '01011112222', 'type' => 'driver', 'is_active' => true]);
        $this->driver = Driver::query()->create([
            'user_id' => $captain->id, 'city_id' => $this->city->id, 'vehicle_type' => 'motorcycle',
            'vehicle_plate' => 'ط ر 1234', 'national_id' => '29901011234567', 'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0, 'is_online' => true, 'is_active' => true, 'is_available' => false,
            'active_order_id' => $this->order->id,
        ]);
        $this->driver->forceFill(['cash_in_hand' => 500, 'wallet_balance' => 300])->save();

        $this->order->update(['status' => 'driver_assigned', 'driver_id' => $this->driver->id]);
    }

    public function test_the_place_sees_the_customer_and_the_captain_in_full_and_none_of_his_internals(): void
    {
        Sanctum::actingAs($this->order->restaurant->owner);

        $payload = $this->withHeaders(['X-App' => 'restaurant'])
            ->getJson('/api/v1/restaurant/orders/'.$this->order->id)
            ->assertOk()
            ->json('data');

        $this->assertSame('أحمد', $payload['customer']['name']);
        $this->assertSame($this->customer->phone, $payload['customer']['phone'], 'unmasked');
        $this->assertSame('12', $payload['customer']['address']['building']);
        $this->assertSame('بجوار المسجد', $payload['customer']['address']['landmark']);
        $this->assertSame('وسط جمصة', $payload['customer']['address']['area']);

        $this->assertSame('01011112222', $payload['captain']['phone']);
        $this->assertSame('ط ر 1234', $payload['captain']['vehicle_plate']);
        $this->assertSame('دراجة نارية', $payload['captain']['vehicle_label']);

        $this->assertEquals(90, $payload['money']['paid_at_pickup']);
        $this->assertArrayNotHasKey('driver_earning', $payload['money']);

        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('29901011234567', $raw, 'a national id reached the place');
        $this->assertArrayNotHasKey('cash_in_hand', $payload['driver']);
        $this->assertArrayNotHasKey('wallet_balance', $payload['driver']);
        $this->assertArrayNotHasKey('earning_type', $payload['driver']);
    }

    public function test_the_captain_sees_the_customer_by_name_and_the_place_without_its_commission(): void
    {
        Sanctum::actingAs($this->driver->user);

        $payload = $this->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/driver/orders/active')
            ->assertOk()
            ->json('data');

        // The job screen read a guest-only field, so no marketplace order ever had a name.
        $this->assertSame('أحمد', $payload['customer']['name']);
        $this->assertSame($this->customer->phone, $payload['customer']['phone']);
        $this->assertSame('3', $payload['customer']['address']['floor']);
        $this->assertSame($this->order->restaurant->phone, $payload['place']['phone']);
        $this->assertEquals(90, $payload['paid_at_pickup']);
        $this->assertEquals(115, $payload['cash_to_collect']);
        $this->assertArrayNotHasKey('commission_rate', $payload['restaurant']);
    }

    public function test_the_customer_sees_the_place_and_the_captain_they_are_waiting_for(): void
    {
        Sanctum::actingAs($this->customer);

        $sub = $this->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/orders/'.$this->order->order_group_id)
            ->assertOk()
            ->json('data.sub_orders.0');

        $this->assertSame('ط ر 1234', $sub['driver']['vehicle_plate']);
        $this->assertSame('01011112222', $sub['captain']['phone']);
        $this->assertSame($this->order->restaurant->phone, $sub['place']['phone']);
        $this->assertArrayNotHasKey('restaurant_amount', $sub['money']);
    }

    public function test_a_direct_order_is_priced_by_the_area_the_place_picks(): void
    {
        $far = $this->city->serviceZones()->create([
            'name_ar' => 'زيان', 'name_en' => 'Zayan', 'delivery_fee' => 70,
            'center_lat' => 31.4600, 'center_lng' => 31.5600,
            'min_order_value' => 0, 'avg_delivery_minutes' => 40, 'is_active' => true, 'is_selectable' => true,
        ]);

        // The pin sits in the centre zone; the place says the customer is in زيان.
        $quote = app(QuoteDirectOrderAction::class)->execute($this->order->restaurant, 31.3835, 31.4835, $far->id);

        $this->assertEquals(70, $quote['delivery_fee']);
        $this->assertSame($far->id, $quote['service_zone_id']);
    }
}
