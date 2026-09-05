<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `DRV-27`'s range chip, moved from the app to `GET /driver/orders`.
 *
 * The app used to page the endpoint and then drop the rows that fell outside
 * «اليوم» / «هذا الأسبوع» / «هذا الشهر» itself. Both halves of that are wrong
 * once the list pages: the count under the header comes from a `total` that
 * counts rows the app hid, and a captain whose recent page is all older trips
 * gets an empty screen with «there is more» behind it.
 *
 * The date compared is `delivered_at`, falling back to `created_at` for a trip
 * that never finished — which is the rule the app applied, kept so that a trip
 * taken at 23:50 and delivered after midnight still counts as today's.
 */
class DriverHistoryRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    public function test_the_range_filter_narrows_the_list_and_the_total_with_it(): void
    {
        [$driver, $restaurant] = $this->makeDriverWithRestaurant();

        $this->makeTrip($driver, $restaurant, now()->subHours(2));
        $this->makeTrip($driver, $restaurant, now()->subDays(3));
        $this->makeTrip($driver, $restaurant, now()->subDays(20));

        Sanctum::actingAs($driver->user);

        $all = $this->withHeaders(['X-App' => 'app'])->getJson('/api/v1/driver/orders');
        $all->assertOk();
        $this->assertSame(3, $all->json('meta.total'));

        $week = $this->withHeaders(['X-App' => 'app'])->getJson(
            '/api/v1/driver/orders?'.http_build_query(['filter' => ['from' => now()->subDays(7)->toDateTimeString()]]),
        );
        $week->assertOk();

        // Two trips, and a `total` that says two — the number DRV-27 prints.
        $this->assertSame(2, $week->json('meta.total'));
        $this->assertCount(2, $week->json('data'));
    }

    public function test_a_trip_taken_before_midnight_and_delivered_after_it_counts_as_todays(): void
    {
        [$driver, $restaurant] = $this->makeDriverWithRestaurant();

        $order = $this->makeTrip($driver, $restaurant, now()->startOfDay()->subMinutes(10));
        $order->update(['delivered_at' => now()->startOfDay()->addMinutes(10)]);

        Sanctum::actingAs($driver->user);

        $today = $this->withHeaders(['X-App' => 'app'])->getJson(
            '/api/v1/driver/orders?'.http_build_query(['filter' => ['from' => now()->startOfDay()->toDateTimeString()]]),
        );

        $today->assertOk();
        $this->assertSame(1, $today->json('meta.total'));
    }

    /** @return array{0: Driver, 1: Restaurant} */
    private function makeDriverWithRestaurant(): array
    {
        $user = User::query()->create([
            'name' => 'كابتن محمود',
            'phone' => '01055556666',
            'type' => 'driver',
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        $driver = $user->driver()->create([
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'is_active' => true,
        ]);

        return [$driver->fresh('user'), $this->makeRestaurant('سندوتش عمر')];
    }

    private function makeTrip(Driver $driver, $restaurant, $at): Order
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $product = $this->makeProduct($restaurant, 'سندوتش', 40);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        $order->forceFill([
            'driver_id' => $driver->id,
            'status' => 'delivered',
            'delivered_at' => $at,
            'created_at' => $at,
        ])->save();

        return $order;
    }
}
