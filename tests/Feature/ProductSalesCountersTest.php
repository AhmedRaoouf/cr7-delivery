<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\Restaurant;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 110 — Ahmed Raouf, 2026-11-24, on ADM-43: «التقرير ده مش ظاهر في عدد مرات البيع
 * ولا التقييم و ده لكل الأماكن». `products.sold_count`, the dish rating and
 * `restaurants.orders_count` were read everywhere and written nowhere.
 */
class ProductSalesCountersTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    public function test_a_delivery_counts_units_and_a_rating_reaches_the_dish_and_the_report(): void
    {
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);

        $place = $this->makeRestaurant('حلواني الكنافة');
        $kunafa = $this->makeProduct($place, 'كنافة نوتيلا', 120);
        $cheese = $this->makeProduct($place, 'كنافة جبنة', 100);

        $first = $this->basket([[$place, $kunafa, 2], [$place, $cheese, 1]]);
        $second = $this->basket([[$place, $kunafa, 3]]);

        // Not delivered yet: nothing sold.
        $this->assertSame(0, (int) $kunafa->fresh()->sold_count);

        $this->deliver($first);

        $this->assertSame(2, (int) $kunafa->fresh()->sold_count);
        $this->assertSame(1, (int) $cheese->fresh()->sold_count);
        $this->assertSame(1, (int) $place->fresh()->orders_count);

        $this->deliver($second);

        $this->assertSame(5, (int) $kunafa->fresh()->sold_count);
        $this->assertSame(2, (int) $place->fresh()->orders_count);

        // The customer rates the place; the dishes on that order carry the stars.
        Sanctum::actingAs($first->user);
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/orders/'.$first->id.'/ratings', [
                'restaurants' => [['restaurant_id' => $place->id, 'stars' => 4]],
            ])->assertOk();

        Sanctum::actingAs($second->user);
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/orders/'.$second->id.'/ratings', [
                'restaurants' => [['restaurant_id' => $place->id, 'stars' => 5]],
            ])->assertOk();

        $this->assertSame('4.50', (string) $kunafa->fresh()->rating_avg);
        $this->assertSame(2, (int) $kunafa->fresh()->rating_count);
        $this->assertSame('4.00', (string) $cheese->fresh()->rating_avg);

        // ADM-43 reads them.
        $rows = collect($this->actingAsAdmin($this->globalAdmin())
            ->getJson('/api/v1/admin/reports/products?filter[restaurant_id]='.$place->id)
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertSame(5, (int) $rows[$kunafa->id]['sold_count']);
        $this->assertEquals(4.5, (float) $rows[$kunafa->id]['rating_avg']);

        // An order that leaves `delivered` takes its units back.
        $order = Order::query()->where('order_group_id', $second->id)->first();
        $order->status = OrderStatus::Cancelled;
        $order->save();

        $this->assertSame(2, (int) $kunafa->fresh()->sold_count);
        $this->assertSame(1, (int) $place->fresh()->orders_count);
    }

    public function test_the_recount_command_backfills_from_history(): void
    {
        $this->seedLocation();

        $place = $this->makeRestaurant('زهو');
        $dish = $this->makeProduct($place, 'شاورما', 80);
        $group = $this->basket([[$place, $dish, 4]]);

        // History written before the observer counted anything.
        DB::table('orders')->where('order_group_id', $group->id)->update(['status' => 'delivered', 'delivered_at' => now()]);
        DB::table('products')->update(['sold_count' => 0]);
        DB::table('restaurants')->update(['orders_count' => 0]);

        $this->artisan('cr7:recount-sales')->assertSuccessful();

        $this->assertSame(4, (int) $dish->fresh()->sold_count);
        $this->assertSame(1, (int) $place->fresh()->orders_count);
    }

    /** @param  array<int, array{0: Restaurant, 1: Product, 2: int}>  $lines */
    private function basket(array $lines): OrderGroup
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, $lines);

        return app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );
    }

    /** Through the model, the way every production status write goes. */
    private function deliver(OrderGroup $group): void
    {
        foreach (Order::query()->where('order_group_id', $group->id)->get() as $order) {
            $order->status = OrderStatus::Delivered;
            $order->delivered_at = now();
            $order->save();
        }
    }
}
