<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ServiceZone;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\FirstOrderPerk;
use App\Support\MapStyles;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-81 / D-82 / D-83 — the address book, and the three things that were wrong
 * downstream of it.
 *
 * Operator, 2026-09-11: «لما اختار المنطقه يختارها فعلا ويجدد السعر علي اساسها»
 * · «لما يعمل خطأ في اضافه العنوان يقلي اي بالظبط الغلط» · «الداتا اللي في
 * العنوان والسعر والموبايل وكله يبقا هما اللي في الاوردر … ولو مفيش رقم تواصل
 * اتحط يبقا رقم الحساب نفسه».
 */
class AddressBookAndAreaPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(deliveryFee: 20.0);
        $this->customer = $this->makeCustomer();
        Sanctum::actingAs($this->customer);
        // `EnsureClientMatchesUserType` — the customer and the captain share
        // one binary, so every call from it says which one it is.
        $this->withHeaders(['X-App' => 'app']);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'home',
            'street' => 'شارع البحر',
            'city_id' => $this->city->id,
            'zone_id' => $this->zone->id,
            'latitude' => 31.3835,
            'longitude' => 31.4835,
        ], $overrides);
    }

    public function test_every_refusal_names_its_own_field_in_arabic(): void
    {
        $response = $this->postJson('/api/v1/addresses', [
            'label' => 'other',
            'contact_phone' => '0123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'title', 'zone_id', 'latitude', 'longitude', 'contact_phone',
        ]);

        // `city_id` is deliberately **not** on that list any more. The app has no
        // city step since the operator ruled «هي مناطق على طول» (2026-09-12), so
        // it sends none, and the server derives it from the area — which it could
        // always have done, since every area belongs to exactly one city.
        // Demanding it back would be asking the customer to fix a field no screen
        // shows them.
        //
        // Nor is `street`, since D-111: an address may be saved without one (the
        // first-launch address usually has none) and checkout names it instead —
        // `AddressCompletenessCheckoutTest`.
        $response->assertJsonMissingValidationErrors(['city_id', 'street']);

        $errors = $response->json('errors');

        // The sentences, not only the keys: Laravel's own «The zone id field is
        // required.» is what the customer used to be shown, in a grey toast,
        // with nothing marked on the form.
        $this->assertSame('اختر المنطقة — سعر التوصيل يتحدد على أساسها.', $errors['zone_id'][0]);
        $this->assertSame('اكتب اسمًا لهذا العنوان.', $errors['title'][0]);
        $this->assertStringContainsString('رقم هاتف مصري صحيح', $errors['contact_phone'][0]);
        $this->assertSame('حدد موقع العنوان على الخريطة.', $errors['latitude'][0]);
    }

    public function test_a_blank_phone_is_no_phone_and_a_pasted_one_is_normalised(): void
    {
        // The normalisation is the point here, not D-112's proof by SMS — which has its
        // own test, `AddressPhoneVerificationTest`.
        Settings::set('addresses.alternate_phone_requires_otp', false, 'addresses');

        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => '   ']))
            ->assertCreated();

        // `''` is not `null`: `nullable` lets it through, it is stored, and
        // `contact_phone ?? user->phone` then answers the empty string — which
        // is what left «اتصال» with nothing to dial (D-83).
        $this->assertNull(UserAddress::query()->latest('id')->first()->contact_phone);

        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => '+20 109 000 9599']))
            ->assertCreated();

        $this->assertSame('01090009599', UserAddress::query()->latest('id')->first()->contact_phone);
    }

    public function test_the_list_carries_the_names_the_fee_and_the_number_that_will_be_called(): void
    {
        $address = $this->makeAddress($this->customer);
        $address->update(['building' => '7', 'floor' => '3', 'landmark' => 'بجوار المسجد']);

        $row = $this->getJson('/api/v1/addresses')->assertOk()->json('data.0');

        // `decimal:7` casts serialise as strings on a raw model — the defect
        // `CityResource` was written for, repeated here for two releases.
        $this->assertIsFloat($row['latitude']);
        $this->assertIsFloat($row['longitude']);

        $this->assertSame('وسط جمصة', $row['zone']['name_ar']);
        // JSON drops a zero fraction, so the wire says `20`; what matters is the
        // value, and the client reads it through `asDouble`.
        $this->assertSame(20.0, (float) $row['delivery_fee']);
        $this->assertSame($this->customer->phone, $row['effective_contact_phone']);
        $this->assertNull($row['contact_phone']);

        // D-83 — the area and the city are part of the address, and the old
        // composer dropped both.
        $this->assertStringContainsString('شارع البحر', $row['formatted_address']);
        $this->assertStringContainsString('بجوار المسجد', $row['formatted_address']);
        $this->assertStringContainsString('وسط جمصة', $row['formatted_address']);
        $this->assertStringContainsString('جمصة', $row['formatted_address']);
    }

    public function test_the_order_carries_the_address_and_falls_back_to_the_account_number(): void
    {
        $address = $this->makeAddress($this->customer);
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'ساندويتش', 40);
        $this->makeCart($this->customer, [[$restaurant, $product, 2]]);

        $this->postJson('/api/v1/orders', [
            'address_id' => $address->id,
            'payment_method' => 'cash',
        ], ['Idempotency-Key' => 'addr-test-1'])->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertSame($this->customer->phone, $order->delivery_contact_phone);
        $this->assertStringContainsString('شارع البحر', $order->delivery_address_text);
        $this->assertStringContainsString('وسط جمصة', $order->delivery_address_text);

        // And the address's own number wins when it has one.
        $address->update(['contact_phone' => '01090009599']);
        $this->assertSame('01090009599', $address->fresh()->resolvedContactPhone($this->customer));
    }

    public function test_the_basket_is_priced_by_the_area_of_the_default_address(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'ساندويتش', 40);
        $this->makeCart($this->customer, [[$restaurant, $product, 1]]);

        // No address yet: the cheapest **selectable** area of the launch city,
        // which is a price the operator actually printed rather than a guess.
        $this->zone->update(['is_selectable' => true]);
        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
        $this->assertSame(20.0, (float) $cart['delivery_base_fee']);
        $this->assertSame(10.0, (float) $cart['extra_stop_fee']);
        $this->assertNull($cart['delivery_address_id']);

        // With one, it is that address's own area.
        $expensive = ServiceZone::query()->create([
            'city_id' => $this->city->id,
            'name_ar' => 'زيان',
            'name_en' => 'Zayan',
            'center_lat' => 31.3836,
            'center_lng' => 31.4836,
            'radius_km' => 3,
            'delivery_fee' => 70,
            'min_order_value' => 0,
            'avg_delivery_minutes' => 40,
            'is_active' => true,
            'is_selectable' => true,
        ]);

        $address = $this->makeAddress($this->customer);
        $address->update(['service_zone_id' => $expensive->id]);

        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
        $this->assertSame(70.0, (float) $cart['delivery_base_fee']);
        $this->assertSame($address->id, $cart['delivery_address_id']);

        // The welcome offer would zero the fee and hide the very thing under
        // test — that the **price** follows the area.
        Settings::set(FirstOrderPerk::SETTING, false);

        // D-81 — and the quote, which is what bills, says the same number.
        $quote = $this->postJson('/api/v1/checkout/quote', [
            'address_id' => $address->id,
            'payment_method' => 'cash',
        ])->assertOk()->json('data');

        $this->assertSame(70.0, (float) $quote['delivery_fee']);

        // Move the address to the cheap area and the price follows it. This is
        // the whole of «يختارها فعلًا ويجدّد السعر على أساسها».
        $this->patchJson("/api/v1/addresses/{$address->id}", ['zone_id' => $this->zone->id])
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', fn ($fee) => (float) $fee === 20.0);

        $quote = $this->postJson('/api/v1/checkout/quote', [
            'address_id' => $address->id,
            'payment_method' => 'cash',
        ])->assertOk()->json('data');

        $this->assertSame(20.0, (float) $quote['delivery_fee']);
    }

    public function test_geocoding_is_available_without_any_credential(): void
    {
        config()->set('maps.mapbox.token', null);
        config()->set('maps.carto.api_key', null);
        config()->set('maps.provider', 'osm');

        // D-84 — the flag used to be a property of the **tile** provider, so
        // the OSM basemap reported `geocoding: false` and the app stopped
        // asking for street names on exactly the deployments that have no
        // Mapbox token.
        $this->assertTrue(MapStyles::hasGeocoder());
        $this->assertTrue(MapStyles::payload()['geocoding']);
    }
}
