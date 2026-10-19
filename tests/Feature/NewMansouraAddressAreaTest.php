<?php

namespace Tests\Feature;

use App\Models\ServiceZone;
use Database\Seeders\OperatorZonePricingSeeder;
use Database\Seeders\ServiceAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 2026-10-19 — «المنصورة الجديدة على الخريطة بس مش لاقينها في الخيارات».
 *
 * The area list is flat, but an installed app offers only the slice that hangs off one
 * city, and the operator filed six of New Mansoura's areas under Gamasa on 2026-10-12.
 * A pin in New Mansoura could then only be saved under an area whose `city_id` differed
 * from the pin's city — and the server answered «المنطقة المختارة بعيدة عن الموقع».
 */
class NewMansouraAddressAreaTest extends TestCase
{
    use RefreshDatabase;

    /** The Google Maps destination the operator sent: inside New Mansoura. */
    private const PIN = [31.469737, 31.445204];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ServiceAreaSeeder::class, OperatorZonePricingSeeder::class]);
        Sanctum::actingAs($this->makeCustomer());
        $this->withHeader('X-App', 'app');
    }

    private function save(string $zoneName): \Illuminate\Testing\TestResponse
    {
        $zone = ServiceZone::query()->where('name_ar', $zoneName)->firstOrFail();

        return $this->postJson('/api/v1/addresses', [
            'label' => 'home',
            'street' => 'المنصورة الجديدة - سكن مصر 2',
            'zone_id' => $zone->id,
            'latitude' => self::PIN[0],
            'longitude' => self::PIN[1],
        ]);
    }

    public function test_a_new_mansoura_pin_saves_under_an_area_filed_under_gamasa(): void
    {
        $this->save('جامعة المنصورة الأهلية')->assertCreated();
        $this->save('الصناعية الأولى')->assertCreated();
    }

    public function test_a_new_mansoura_pin_saves_under_its_own_areas(): void
    {
        $this->save('سكن 2')->assertCreated();
        $this->save('زاهية 4')->assertCreated();
    }

    public function test_a_new_mansoura_pin_cannot_claim_a_gamasa_town_area(): void
    {
        // The seeder leaves every flyer area shapeless; production placed these two (doc 59).
        ServiceZone::query()->where('name_ar', 'داخلي جمصة')
            ->update(['center_lat' => 31.4375, 'center_lng' => 31.5433, 'radius_km' => 1.6]);
        ServiceZone::query()->where('name_ar', 'جمصة البلد')
            ->update(['center_lat' => 31.4345, 'center_lng' => 31.5505, 'radius_km' => 0.8]);

        $this->save('داخلي جمصة')->assertStatus(422)->assertJsonValidationErrors('zone_id');
        $this->save('جمصة البلد')->assertStatus(422)->assertJsonValidationErrors('zone_id');
    }
}
