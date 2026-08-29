<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\ServiceZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A number on the wire is a number.
 *
 * `/cities` and `/cities/{city}/zones` used to return the Eloquent models raw.
 * Every `decimal:` cast serialises as a **string**, so the payload carried
 * `"center_lat": "31.3833000"` and `"delivery_fee": "10.00"`, and the mobile
 * client — reading them as numbers, which is what they are — threw
 * `type 'String' is not a subtype of type 'num?'` on the first zone it parsed.
 * That crash took the whole customer home feed down with it.
 *
 * These are the two public routes the app reads geometry from before anyone
 * has signed in, so they are the two that must never regress.
 */
class JsonNumberContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * JSON has one number type: `json_decode` hands back an `int` for `50.0`
     * and a `float` for `31.3833`, so asserting the PHP type would pin the
     * value, not the contract. What the client cares about is the one thing
     * that broke it — a number arriving quoted.
     */
    private function assertJsonNumber(mixed $value, string $field): void
    {
        $this->assertIsNotString($value, "`{$field}` came back as a string");
        $this->assertIsNumeric($value, "`{$field}` is not a number");
    }

    private function gamasa(): City
    {
        return City::create([
            'name_ar' => 'جمصة',
            'name_en' => 'Gamasa',
            'center_lat' => 31.3833,
            'center_lng' => 31.4833,
            'pricing_type' => 'fixed_zone',
            'default_min_order' => 50,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_cities_sends_numbers_not_strings(): void
    {
        $this->gamasa();

        $city = $this->getJson('/api/v1/cities')->assertOk()->json('data.0');

        $this->assertJsonNumber($city['center_lat'], 'center_lat');
        $this->assertJsonNumber($city['center_lng'], 'center_lng');
        $this->assertJsonNumber($city['default_min_order'], 'default_min_order');
        $this->assertJsonNumber($city['sort_order'], 'sort_order');
        $this->assertEqualsWithDelta(31.3833, $city['center_lat'], 0.0001);

        // Both languages, whatever the header says: the client caches these
        // rows for the session and renders either one from the same copy.
        $this->assertSame('جمصة', $city['name_ar']);
        $this->assertSame('Gamasa', $city['name_en']);

        // Internals stay on the server.
        $this->assertArrayNotHasKey('created_at', $city);
        $this->assertArrayNotHasKey('updated_at', $city);
    }

    public function test_zones_send_numbers_not_strings(): void
    {
        $city = $this->gamasa();
        ServiceZone::create([
            'city_id' => $city->id,
            'name_ar' => 'وسط جمصة',
            'name_en' => 'Gamasa Center',
            'center_lat' => 31.3833,
            'center_lng' => 31.4833,
            'radius_km' => 3,
            'delivery_fee' => 10,
            'min_order_value' => 50,
            'avg_delivery_minutes' => 30,
            'is_active' => true,
        ]);

        $zone = $this->getJson("/api/v1/cities/{$city->id}/zones")->assertOk()->json('data.0');

        $this->assertJsonNumber($zone['center_lat'], 'center_lat');
        $this->assertJsonNumber($zone['center_lng'], 'center_lng');
        $this->assertJsonNumber($zone['radius_km'], 'radius_km');
        $this->assertJsonNumber($zone['delivery_fee'], 'delivery_fee');
        $this->assertJsonNumber($zone['min_order_value'], 'min_order_value');
        $this->assertJsonNumber($zone['avg_delivery_minutes'], 'avg_delivery_minutes');
        $this->assertIsBool($zone['is_active']);

        // `(float) null` is `0.0`, and a client reading `0` for «no threshold»
        // draws a «توصيل مجاني» chip on every card. The null has to survive.
        $this->assertNull($zone['free_delivery_over']);

        $this->assertArrayNotHasKey('created_at', $zone);
    }
}
