<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `RES-03`'s ready cards rendered «كود الاستلام ——» for every order ever placed.
 *
 * The card reads `order.pickup_otp` off the board list; `Order::$hidden` carries
 * `pickup_otp` so it can never ride along on a customer or driver payload. Both are
 * right on their own, and together they meant the one screen that is supposed to
 * hold the code was the one screen that never saw it — while the dedicated endpoint
 * beside it returned the number correctly and nothing called it.
 *
 * The code direction is worth restating because it reads backwards from the screen:
 * the **restaurant** holds the pickup OTP and reads it out; the **captain** types it
 * into their app. doc 04 §11.1.
 */
class PickupOtpVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    private function makeOrder(Restaurant $restaurant): Order
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        return Order::query()->where('order_group_id', $group->id)->firstOrFail();
    }

    private function actingAsRestaurant(Restaurant $restaurant): void
    {
        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');
    }

    public function test_a_ready_card_carries_the_code_the_captain_must_quote(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Ready]);

        $this->actingAsRestaurant($restaurant);

        $response = $this->getJson('/api/v1/restaurant/orders?filter[queue]=ready')->assertOk();

        $this->assertArrayHasKey('pickup_otp', $response->json('data.0'));
        $this->assertSame(
            $order->getRawOriginal('pickup_otp'),
            $response->json('data.0.pickup_otp'),
        );
    }

    public function test_a_card_that_is_not_ready_yet_still_hides_it(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $this->makeOrder($restaurant);

        $this->actingAsRestaurant($restaurant);

        $response = $this->getJson('/api/v1/restaurant/orders?filter[queue]=new')->assertOk();

        // Nothing to hand over before the food exists, and a code printed early is a
        // code a captain can quote before the order is packed.
        $this->assertArrayNotHasKey('pickup_otp', $response->json('data.0'));
    }

    public function test_the_order_detail_shows_it_too(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::DriverAssigned]);

        $this->actingAsRestaurant($restaurant);

        $this->getJson("/api/v1/restaurant/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.pickup_otp', $order->getRawOriginal('pickup_otp'));
    }

    public function test_the_customer_never_receives_the_pickup_code(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::Ready]);

        Sanctum::actingAs($order->user);
        $this->withHeader('X-App', 'app');

        $response = $this->getJson("/api/v1/orders/{$order->order_group_id}")->assertOk();

        $this->assertStringNotContainsString(
            (string) $order->getRawOriginal('pickup_otp'),
            json_encode($response->json('data.orders') ?? [], JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    public function test_the_dedicated_endpoint_and_the_list_agree_on_when_it_is_available(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $this->actingAsRestaurant($restaurant);

        // The gate is one constant now. Before, the endpoint had the list inline and the
        // board had no gate at all because it had no code to gate.
        $this->getJson("/api/v1/restaurant/orders/{$order->id}/pickup-otp")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_transition');

        $order->update(['status' => OrderStatus::Ready]);

        $this->getJson("/api/v1/restaurant/orders/{$order->id}/pickup-otp")
            ->assertOk()
            ->assertJsonPath('data.pickup_otp', $order->getRawOriginal('pickup_otp'));
    }
}
