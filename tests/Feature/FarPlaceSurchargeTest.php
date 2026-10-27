<?php

namespace Tests\Feature;

use App\Actions\Restaurant\QuoteDirectOrderAction;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Support\CheckoutPricer;
use App\Support\FirstOrderPerk;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 97 — places in New Damietta, ordered from Gamasa. «زود 100 جنيه علي كل منطقه
 * محطوطه لو هيطلب من دمياط الجديده» — operator, 2026-10-27.
 *
 * Before: the fee was the customer's area and nothing else, so a basket from a town
 * 15 km away cost what one from the next street did, and the captain's 85% of it.
 */
class FarPlaceSurchargeTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        Settings::set(FirstOrderPerk::SETTING, 0, 'delivery');
    }

    private function farPlace(string $name = 'باب الحارة', float $surcharge = 100): Restaurant
    {
        $place = $this->makeRestaurant($name, 31.4436, 31.6553);
        $place->update(['delivery_surcharge' => $surcharge]);

        return $place->fresh();
    }

    public function test_a_far_place_adds_its_surcharge_to_the_area_fee(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $far = $this->farPlace();
        $cart = $this->makeCart($customer, [[$far, $this->makeProduct($far, 'شاورما', 100), 1]]);

        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, null, 'cash');

        $this->assertSame(120.0, $quote['delivery_fee']);       // area 20 + 100
        $this->assertSame(120.0, $quote['delivery_base_fee']);
        $this->assertSame(100.0, $quote['delivery_surcharge']);
        $this->assertSame(120.0, collect($quote['groups'])->sum('delivery_fee_share'));
    }

    public function test_a_basket_with_a_far_and_a_near_place_pays_the_surcharge_once(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $far = $this->farPlace('ع الفحم');
        $alsoFar = $this->farPlace('باب الحارة', 80);
        $near = $this->makeRestaurant('سندوتش عمر');
        $cart = $this->makeCart($customer, [
            [$far, $this->makeProduct($far, 'كفتة', 100), 1],
            [$alsoFar, $this->makeProduct($alsoFar, 'شاورما', 50), 1],
            [$near, $this->makeProduct($near, 'سندوتش', 40), 1],
        ]);

        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, null, 'cash');

        // The largest surcharge, not 180: one trip out. Plus two extra stops.
        $this->assertSame(100.0, $quote['delivery_surcharge']);
        $this->assertSame(20.0 + 100.0 + 2 * 10.0, $quote['delivery_fee']);
    }

    public function test_a_nearby_basket_is_priced_as_before(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $near = $this->makeRestaurant('سندوتش عمر');
        $cart = $this->makeCart($customer, [[$near, $this->makeProduct($near, 'سندوتش', 40), 1]]);

        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, null, 'cash');

        $this->assertSame(20.0, $quote['delivery_fee']);
        $this->assertSame(0.0, $quote['delivery_surcharge']);
    }

    public function test_free_delivery_waives_the_area_fee_and_never_the_surcharge(): void
    {
        $far = $this->farPlace();

        Settings::set(FirstOrderPerk::SETTING, 1, 'delivery');
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $cart = $this->makeCart($customer, [[$far, $this->makeProduct($far, 'شاورما', 100), 1]]);
        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, null, 'cash');
        $this->assertTrue($quote['first_order_free_applied']);
        $this->assertSame(100.0, $quote['delivery_fee']);
        $this->assertSame(120.0, $quote['delivery_base_fee']);   // what the strike-through shows

        Settings::set(FirstOrderPerk::SETTING, 0, 'delivery');
        PromoCode::query()->create([
            'code' => 'FREE', 'type' => 'free_delivery', 'value' => 0,
            'title_ar' => 'توصيل مجاني', 'title_en' => 'Free delivery', 'is_active' => true,
        ]);
        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, 'FREE', 'cash');
        $this->assertSame(100.0, $quote['delivery_fee']);
    }

    public function test_the_basket_shows_the_surcharge_before_checkout(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAddress($customer);
        $far = $this->farPlace();
        $this->makeCart($customer, [[$far, $this->makeProduct($far, 'شاورما', 100), 1]]);

        $cart = $this->actingAsCustomer($customer)->getJson('/api/v1/cart')->assertOk()->json('data');

        $this->assertSame(120.0, (float) $cart['delivery_base_fee']);
        $this->assertSame(100.0, (float) $cart['delivery_surcharge']);
    }

    public function test_a_direct_order_from_a_far_place_carries_the_surcharge(): void
    {
        $far = $this->farPlace();
        $far->servedZones()->attach($this->zone->id);

        $quote = app(QuoteDirectOrderAction::class)->execute($far->fresh(), 31.3835, 31.4835, $this->zone->id);

        $this->assertSame(120.0, $quote['delivery_fee']);
    }

    public function test_the_admin_sets_it_on_the_place_and_a_cleared_field_is_zero(): void
    {
        $place = $this->makeRestaurant('تكة');
        $admin = $this->globalAdmin();

        $this->actingAsAdmin($admin)
            ->patchJson("/api/v1/admin/restaurants/{$place->id}", ['delivery_surcharge' => 100])
            ->assertOk()
            ->assertJsonPath('data.delivery_surcharge', '100.00');

        $this->actingAsAdmin($admin)
            ->patchJson("/api/v1/admin/restaurants/{$place->id}", ['delivery_surcharge' => null])
            ->assertOk();
        $this->assertSame('0.00', $place->fresh()->delivery_surcharge);

        $this->actingAsAdmin($admin)
            ->patchJson("/api/v1/admin/restaurants/{$place->id}", ['delivery_surcharge' => -5])
            ->assertUnprocessable();
    }

    public function test_a_place_cannot_set_its_own_surcharge(): void
    {
        $place = $this->farPlace();

        $this->actingAsPlace($place)->patchJson('/api/v1/restaurant/me', ['delivery_surcharge' => 0]);

        $this->assertSame('100.00', $place->fresh()->delivery_surcharge);
    }
}
