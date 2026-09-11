<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\ServiceZone;
use Database\Seeders\OperatorZonePricingSeeder;
use Database\Seeders\ServiceAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * D-70 — the operator's printed price list, and the coverage question it does not answer.
 *
 * The flyer names 31 areas and prices each. None of them is a shape: «جنة 3» and
 * «الصناعية الرابعة» are not in any geocoder, so no pin ever lands inside one and the
 * customer has to pick. What must therefore keep working is the *other* question —
 * «هل توصّلون هنا أصلًا» — and that is the city circle.
 *
 * The failure this guards against is the one that would have shipped silently: take the
 * wide legacy circles off the price list without adding the city fallback, and every
 * address in المنصورة الجديدة answers «خارج نطاق التوصيل».
 */
class OperatorZonePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ServiceAreaSeeder::class, OperatorZonePricingSeeder::class]);
    }

    private function resolve(float $lat, float $lng): TestResponse
    {
        return $this->postJson('/api/v1/geo/resolve', ['lat' => $lat, 'lng' => $lng]);
    }

    public function test_the_flyer_prices_are_the_prices(): void
    {
        // A spread across both columns, including the two extremes. If anyone
        // "corrects" these from distance, these are the rows that will break.
        $expected = [
            'داخلي جمصة' => 20.0,
            'جمصة البلد' => 25.0,
            'زيان' => 70.0,
            'جامعة المنصورة الأهلية' => 60.0,
            'الصناعية الأولى' => 30.0,
            'الصناعية الرابعة' => 55.0,
            'كورنيش البحر' => 80.0,
            'زاهية 4' => 150.0,
        ];

        foreach ($expected as $name => $fee) {
            $zone = ServiceZone::query()->where('name_ar', $name)->first();

            $this->assertNotNull($zone, "«{$name}» is missing from the price list");
            $this->assertSame($fee, (float) $zone->delivery_fee, "«{$name}» is priced wrong");
            $this->assertTrue((bool) $zone->is_selectable);
            // A priced area is picked, never matched. A radius here would make a pin
            // land in it and quietly overrule the customer's own choice.
            $this->assertNull($zone->radius_km, "«{$name}» must not be a circle");
        }
    }

    public function test_the_whole_flyer_is_there_and_nothing_else_is_offered_as_a_price(): void
    {
        $gamasa = City::query()->where('name_en', 'Gamasa')->sole();
        $mansoura = City::query()->where('name_en', 'New Mansoura')->sole();

        $this->assertSame(16, ServiceZone::query()->where('city_id', $gamasa->id)->where('is_selectable', true)->count());
        $this->assertSame(15, ServiceZone::query()->where('city_id', $mansoura->id)->where('is_selectable', true)->count());

        // The wide legacy circles survive — restaurants, addresses and
        // `service_zone_driver` point at them — they are only off the price list.
        $legacy = ServiceZone::query()->where('name_en', 'Gamasa Beach')->sole();
        $this->assertTrue((bool) $legacy->is_active);
        $this->assertFalse((bool) $legacy->is_selectable);
    }

    public function test_a_pin_inside_a_legacy_circle_still_resolves_and_still_asks_for_an_area(): void
    {
        // The Gamasa beach strip: the legacy circle catches it, so `zone` comes back —
        // but it is not a priced area, so the customer is still asked.
        $response = $this->resolve(31.4411, 31.5380)->assertOk();

        $this->assertTrue($response->json('data.is_serviceable'));
        $this->assertTrue($response->json('data.requires_zone_choice'));
        $this->assertNotEmpty($response->json('data.zones'));
    }

    public function test_a_pin_in_new_mansoura_is_covered_by_the_city_and_priced_by_the_choice(): void
    {
        // 13.3 km north of the city centre: outside every zone circle in the country —
        // New Mansoura's own legacy circle is 12 km — and inside the city's 15 km. This
        // is the exact gap that answered «خارج نطاق التوصيل» before the fallback, and
        // «سكن 3» is squarely in it.
        $response = $this->resolve(31.5636, 31.6553)->assertOk();

        $this->assertTrue($response->json('data.is_serviceable'));
        $this->assertNull($response->json('data.zone'));
        $this->assertTrue($response->json('data.requires_zone_choice'));
        $this->assertSame('المنصورة الجديدة', $response->json('data.city.name_ar'));

        // `zones` is now **every** priced area, not the resolved city's slice
        // (operator ruling 2026-09-12 — one flat list, no city step). The city's
        // own fifteen are still all present and still in flyer order; what
        // changed is that Gamasa's are there too, because a customer standing in
        // one town and ordering to another was previously shown nothing they
        // could pick.
        $zones = $response->json('data.zones');
        $mansoura = array_values(array_filter(
            $zones,
            fn (array $zone) => $zone['city_id'] === $response->json('data.city.id'),
        ));

        $this->assertGreaterThan(15, count($zones));
        $this->assertCount(15, $mansoura);
        // Cheapest first — a price list is read in price order.
        $this->assertSame('الصناعية الأولى', $mansoura[0]['name_ar']);
        $this->assertSame(30.0, (float) $mansoura[0]['delivery_fee']);
        $this->assertSame('زاهية 4', $mansoura[14]['name_ar']);
        $this->assertSame(150.0, (float) $mansoura[14]['delivery_fee']);
    }

    public function test_a_pin_nowhere_near_any_city_is_still_out_of_range(): void
    {
        // Aswan. The fallback widens coverage to a city circle, not to the country.
        $response = $this->resolve(24.0889, 32.8998)->assertOk();

        $this->assertFalse($response->json('data.is_serviceable'));
        $this->assertNull($response->json('data.city'));
        $this->assertNull($response->json('data.suggested_zone_id'));

        // The area list still comes back, and that is the point of the flat list:
        // «we cannot place your pin» and «here is nowhere you can order to» are
        // different answers, and the second one strands a customer whose GPS is
        // wrong or off with no way forward. The screen reads this as «اختر
        // منطقتك» — which is the manual route the operator asked to always be
        // reachable.
        $this->assertNotEmpty($response->json('data.zones'));
    }

    public function test_the_public_zone_list_can_be_narrowed_to_what_a_customer_may_pick(): void
    {
        $gamasa = City::query()->where('name_en', 'Gamasa')->sole();

        $all = $this->getJson("/api/v1/cities/{$gamasa->id}/zones")->assertOk();
        $priced = $this->getJson("/api/v1/cities/{$gamasa->id}/zones?selectable=1")->assertOk();

        $this->assertCount(18, $all->json('data'));
        $this->assertCount(16, $priced->json('data'));
        $this->assertSame('داخلي جمصة', $priced->json('data.0.name_ar'));
    }
}
