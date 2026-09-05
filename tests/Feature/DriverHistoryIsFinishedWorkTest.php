<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «بخش على الطلبات وأخش على الطلب، بيقول تم التسليم وأرباحك وتقييم العميل — وهو
 * أصلاً لسه ما أخدش الطلب».
 *
 * `DRV-27` is the archive: its filters are «كل الحالات / المسلَّمة / الملغاة» and
 * its card can say exactly two things. The endpoint handed it every order the
 * captain had ever been given, live ones included, so an order that had only
 * just been assigned was drawn as a completed delivery with an earning and «لم
 * يقيمك» under it. The order being carried belongs to `/driver/orders/active`.
 */
class DriverHistoryIsFinishedWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    private function makeDriver(): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => 'كابتن رامي']);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 31.3841,
            'current_lng' => 31.4841,
            'location_updated_at' => now(),
        ]);
    }

    private function makeOrder(Restaurant $restaurant, Driver $driver, OrderStatus $status): Order
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        $order->update(['driver_id' => $driver->id, 'status' => $status]);

        return $order->fresh();
    }

    /** @return array<int, int> */
    private function historyIds(Driver $driver): array
    {
        Sanctum::actingAs($driver->user);

        $response = $this->withHeaders(['X-App' => 'app', 'X-Role' => 'driver'])
            ->getJson('/api/v1/driver/orders')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }

    public function test_an_order_the_captain_is_still_carrying_is_not_in_the_archive(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $driver = $this->makeDriver();

        $live = $this->makeOrder($restaurant, $driver, OrderStatus::DriverAssigned);

        $this->assertNotContains($live->id, $this->historyIds($driver));
    }

    public function test_a_delivered_order_is(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $driver = $this->makeDriver();

        $done = $this->makeOrder($restaurant, $driver, OrderStatus::Delivered);

        $this->assertContains($done->id, $this->historyIds($driver));
    }

    public function test_so_is_a_cancelled_one(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $driver = $this->makeDriver();

        $gone = $this->makeOrder($restaurant, $driver, OrderStatus::Cancelled);

        $this->assertContains($gone->id, $this->historyIds($driver));
    }

    public function test_every_mid_trip_status_is_out(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $driver = $this->makeDriver();

        $live = collect([
            OrderStatus::DriverAssigned,
            OrderStatus::ArrivedRestaurant,
            OrderStatus::PickedUp,
            OrderStatus::OnTheWay,
            OrderStatus::ArrivedCustomer,
        ])->map(fn (OrderStatus $status) => $this->makeOrder($restaurant, $driver, $status)->id);

        $history = $this->historyIds($driver);

        foreach ($live as $id) {
            $this->assertNotContains($id, $history);
        }
    }

    public function test_an_explicit_filter_is_still_honoured(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $driver = $this->makeDriver();

        $done = $this->makeOrder($restaurant, $driver, OrderStatus::Delivered);
        $gone = $this->makeOrder($restaurant, $driver, OrderStatus::Cancelled);

        Sanctum::actingAs($driver->user);

        $ids = collect(
            $this->withHeaders(['X-App' => 'app', 'X-Role' => 'driver'])
                ->getJson('/api/v1/driver/orders?filter[status]=cancelled')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($gone->id, $ids);
        $this->assertNotContains($done->id, $ids);
    }
}
