<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «الموبايل مش موجود في مكان صح، عامل إن هو في بلد عندنا تقريبًا أفريقيا».
 *
 * `0, 0` is a point in the Atlantic three thousand kilometres off West Africa,
 * and it is what a captain row that has never had a fix serialises to whenever
 * the column holds a zero rather than a null. The customer's map put the
 * captain there and drew the delivery line to their door across a continent;
 * `ADM-56` stretched its camera over an ocean to fit the same marker in.
 *
 * Nothing this platform serves is within a thousand kilometres of it.
 */
class NullIslandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    /** @param array<string, mixed> $at */
    private function makeDriver(array $at): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => 'كابتن رامي']);

        return Driver::query()->create(array_merge([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'location_updated_at' => now(),
        ], $at));
    }

    public function test_zero_zero_is_not_a_place(): void
    {
        $driver = $this->makeDriver(['current_lat' => 0, 'current_lng' => 0]);

        $this->assertFalse($driver->hasFix());
    }

    public function test_neither_is_a_pair_of_nulls(): void
    {
        $driver = $this->makeDriver(['current_lat' => null, 'current_lng' => null]);

        $this->assertFalse($driver->hasFix());
    }

    public function test_gamasa_is(): void
    {
        $driver = $this->makeDriver(['current_lat' => 31.3892245, 'current_lng' => 31.4720504]);

        $this->assertTrue($driver->hasFix());
    }

    public function test_the_customers_map_is_not_told_the_captain_is_in_the_atlantic(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $driver = $this->makeDriver(['current_lat' => 0, 'current_lng' => 0]);
        Order::query()->where('order_group_id', $group->id)->update([
            'driver_id' => $driver->id,
            'status' => OrderStatus::DriverAssigned,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->withHeaders(['X-App' => 'app', 'X-Role' => 'customer'])
            ->getJson("/api/v1/orders/{$group->id}/tracking")
            ->assertOk();

        $sub = $response->json('data.sub_orders.0');

        $this->assertNull($sub['driver']['latitude']);
        $this->assertNull($sub['driver']['longitude']);
    }
}
