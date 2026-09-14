<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Support\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /driver/offers` answered **500** on the deployment the first time an offer
 * ever existed on it — 2026-09-05, minutes after `dispatch.offer_to_all_online`
 * made offers reach anybody at all. The captain's screen showed nothing and the
 * push had nothing to open.
 *
 * That is not a coincidence: before that change no `order_driver_attempts` row
 * with `result = sent` had ever been written on that host, so this endpoint had
 * never once been asked a question with data behind it. It has no test either.
 * This is that test.
 */
class OfferPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT = [31.3892245, 31.4720504];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();

        foreach ([
            'dispatch.batch_size' => 3,
            'dispatch.radius_km' => 5,
            'dispatch.offer_ttl_seconds' => 30,
            'dispatch.max_rounds' => 3,
            'dispatch.offer_to_all_online' => 1,
        ] as $key => $value) {
            Setting::query()->create(['key' => $key, 'value' => $value, 'group' => 'test']);
        }
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

    private function makeDriver(string $earningType = 'per_km', bool $withFix = true): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => 'كابتن رامي']);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => $earningType,
            'earning_value' => 4,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => $withFix ? 31.4369403 : null,
            'current_lng' => $withFix ? 31.5267197 : null,
            'location_updated_at' => $withFix ? now() : null,
        ]);
    }

    private function offers(Driver $driver)
    {
        Sanctum::actingAs($driver->user);

        return $this->withHeaders(['X-App' => 'app', 'X-Role' => 'driver'])
            ->getJson('/api/v1/driver/offers');
    }

    public function test_a_live_offer_is_returned_rather_than_a_server_error(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $restaurant->update(['logo' => 'restaurants/ibn-elbalad.png']);
        $order = $this->makeOrder($restaurant);
        $driver = $this->makeDriver();

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->offers($driver)
            ->assertOk()
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.restaurant.name', 'ابن البلد')
            ->assertJsonPath('data.0.restaurant.logo', $restaurant->fresh()->logo);
    }

    /** The captain the broadcast exists for: online, free, and no position ever sent. */
    public function test_an_offer_to_a_captain_with_no_fix_still_renders(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);
        $driver = $this->makeDriver(withFix: false);

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->offers($driver)
            ->assertOk()
            ->assertJsonPath('data.0.restaurant.distance_km', null);
    }

    /** @dataProvider earningTypes */
    public function test_every_earning_model_prices_an_offer(string $type): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد', ...self::RESTAURANT);
        $order = $this->makeOrder($restaurant);
        $driver = $this->makeDriver($type);

        app(DispatchService::class)->dispatchRound($order, 1);

        $this->offers($driver)->assertOk();
    }

    public static function earningTypes(): array
    {
        return [
            'fixed' => ['fixed'],
            'per_km' => ['per_km'],
            'percent_of_delivery_fee' => ['percent_of_delivery_fee'],
        ];
    }
}
