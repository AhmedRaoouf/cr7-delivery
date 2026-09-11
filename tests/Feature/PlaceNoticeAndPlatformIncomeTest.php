<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Models\Notification;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the kitchen is told, and what the platform earns (D-75).
 *
 * Operator, 2026-09-11: «الـ notification تبع قبول الطلب يظهر عدد الأصناف وحساب المطعم
 * فقط وليس الـ total» and «عمولة المنصة اللي هو الأدمن كلها ‪15‬٪ على أي order من فلوس
 * الديليفري — مش عايز حاجة غيرها».
 */
class PlaceNoticeAndPlatformIncomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_order_push_counts_the_food_and_prices_the_kitchens_share(): void
    {
        $this->seedLocation(deliveryFee: 20);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ابن البلد');
        $kushari = $this->makeProduct($restaurant, 'كشري لوكس', 100);
        $pepsi = $this->makeProduct($restaurant, 'بيبسي', 15);
        $this->makeCart($customer, [[$restaurant, $kushari, 2], [$restaurant, $pepsi, 1]]);

        app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $push = Notification::query()
            ->where('user_id', $restaurant->owner->id)
            ->where('type', 'order.new')
            ->firstOrFail();

        $body = $push->payload['body_ar'];

        // 🔴 The count was taken inside the `created` observer — before `CreateOrderAction`
        // had written a single item — so every kitchen in Gamasa was reading «‪0‬ أصناف».
        $this->assertStringContainsString('3', $body, 'three pieces: two kushari and a pepsi');
        $this->assertStringContainsString('حساب المكان', $body);
        // 215 for the food. Not 235: the delivery fee is the customer's number and the
        // captain's, never the kitchen's.
        $this->assertStringContainsString('215.00', $body);
        $this->assertStringNotContainsString('235.00', $body);
    }

    public function test_an_order_carries_no_commission_on_the_food(): void
    {
        $this->seedLocation();

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        // The helper still creates places on 10 %, exactly as the live rows were before
        // the migration zeroed them — the point is that the *order* ignores it.
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش فراخ', 60);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();

        $this->assertSame('0.00', (string) $order->commission_amount);
        $this->assertSame('0.00', (string) $order->commission_rate_snapshot);
        $this->assertSame(60.0, $order->restaurant_amount, 'the place is owed its whole subtotal');
    }

    public function test_the_place_payload_carries_its_own_two_figures(): void
    {
        $this->seedLocation();

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('كنج بروست');
        $product = $this->makeProduct($restaurant, 'بروستد', 50);
        $this->makeCart($customer, [[$restaurant, $product, 2]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        Sanctum::actingAs($restaurant->owner);

        $this->withHeaders(['X-App' => 'restaurant'])
            ->getJson('/api/v1/restaurant/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items_count', 2)
            ->assertJsonPath('data.0.restaurant_amount', 100);

        $orderId = Order::query()->where('order_group_id', $group->id)->value('id');

        $this->withHeaders(['X-App' => 'restaurant'])
            ->getJson('/api/v1/restaurant/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.restaurant_amount', 100);
    }

    public function test_the_pickup_code_stays_with_the_place_until_the_captain_takes_the_food(): void
    {
        $this->seedLocation();

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('صواني الدمياطي');
        $product = $this->makeProduct($restaurant, 'صينية فراخ', 120);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        Sanctum::actingAs($restaurant->owner);

        // Operator, 2026-09-11: «الكود يفضل ظاهر لحد ما الديليفري يوصل المطعم، وليس أول
        // ما يوافق على الطلب». The captain accepting is `driver_assigned`; the handover is
        // `picked_up`, and only then is the code spent.
        foreach (['ready', 'searching_driver', 'waiting_for_driver', 'driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'] as $status) {
            $order->forceFill(['status' => $status])->save();

            $this->withHeaders(['X-App' => 'restaurant'])
                ->getJson('/api/v1/restaurant/orders/'.$order->id)
                ->assertOk()
                ->assertJsonStructure(['data' => ['pickup_otp']]);
        }

        $order->forceFill(['status' => 'picked_up'])->save();

        $this->withHeaders(['X-App' => 'restaurant'])
            ->getJson('/api/v1/restaurant/orders/'.$order->id)
            ->assertOk()
            ->assertJsonMissingPath('data.pickup_otp');
    }
}
