<?php

namespace Tests\Feature;

use App\Services\Maps\LocalGazetteerGeocoder;
use Database\Seeders\GazetteerSeeder;
use Database\Seeders\OperatorZonePricingSeeder;
use Database\Seeders\ServiceAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The launch city's own address book — the fix for «مش عايز جمصة الشاطئ دي تبقى
 * مكتوبة دايمًا».
 *
 * What was actually happening, measured on production 2026-09-12 before any of
 * this existed: Mapbox answered a street for every pin in Gamasa and
 * `neighborhood: null` for every one of them, so the only area name the screen
 * could print was the **delivery zone** — and the one zone circle that covers
 * the town is «الشاطئ», centre 31.4411/31.5380, radius ‪6‬ km. The beach is two
 * rows of buildings facing the sea; the app was calling the whole town by its
 * name, on every address, forever.
 *
 * These tests therefore assert the thing the operator actually complained about
 * — that the **area is right** — rather than merely that some string came back.
 * The previous geocoding tests all passed while the bug was live, because a
 * non-empty line was all any of them checked.
 */
class LocalGazetteerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ServiceAreaSeeder::class, OperatorZonePricingSeeder::class, GazetteerSeeder::class]);
        Cache::flush();

        // Nothing here may reach the network. A test that silently fell through
        // to Nominatim would pass for the wrong reason and keep passing after
        // someone deleted the local tables.
        Http::preventStrayRequests();
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: string}>
     */
    public static function gamasaPins(): array
    {
        // Four real points spread across the town — the same four that were
        // measured against production.
        return [
            'الكورنيش' => [31.4453, 31.5344, 'منطقة آمون'],
            'ابن لقمان' => [31.4424, 31.5450, 'منطقة ابن لقمان'],
            'الكرنك' => [31.4384, 31.5341, 'منطقة الكرنك'],
            'العاشر من رمضان' => [31.4474, 31.5261, 'منطقة العاشر من رمضان'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gamasaPins')]
    public function test_a_pin_in_gamasa_is_named_by_its_own_area(float $lat, float $lng, string $area): void
    {
        $row = (new LocalGazetteerGeocoder)->reverse($lat, $lng);

        $this->assertIsArray($row, 'The launch city must answer from local data, never from a provider.');
        $this->assertSame($area, $row['neighborhood']);
        $this->assertStringContainsString($area, $row['address']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gamasaPins')]
    public function test_no_pin_in_gamasa_is_ever_called_the_beach(float $lat, float $lng): void
    {
        $row = (new LocalGazetteerGeocoder)->reverse($lat, $lng);

        // The regression itself, stated as plainly as it can be. «الشاطئ» is a
        // real zone and a real place; what it is not is the name of every
        // address in Gamasa.
        $this->assertIsArray($row);
        $this->assertStringNotContainsString('الشاطئ', (string) $row['address']);
        $this->assertStringNotContainsString('الشاطئ', (string) $row['neighborhood']);
    }

    public function test_a_house_number_is_only_quoted_when_the_pin_is_on_that_house(): void
    {
        $geocoder = new LocalGazetteerGeocoder;

        // 31.4384/31.5341 sits ‪17‬ m from a mapped door, so the number is the
        // customer's own and belongs on the line.
        $onADoor = $geocoder->reverse(31.4384, 31.5341);
        $this->assertSame('145', $onADoor['house_number']);
        $this->assertStringContainsString('145', $onADoor['address']);

        // 31.4453/31.5344 is ‪100‬ m from the nearest mapped door. Mapbox
        // answered «‪18‬ شارع آمون» there — an interpolation, and a door number
        // that is off by a block sends the captain to a stranger's flat.
        $betweenDoors = $geocoder->reverse(31.4453, 31.5344);
        $this->assertNull($betweenDoors['house_number']);
        $this->assertSame('منطقة آمون', $betweenDoors['neighborhood']);
    }

    public function test_a_pin_outside_the_seeded_town_is_handed_to_the_providers(): void
    {
        // Cairo. Answering here from an address book that stops at Gamasa would
        // be worse than not answering: the nearest row is ‪140‬ km away and the
        // chain would stop at it and never ask a provider.
        $this->assertNull((new LocalGazetteerGeocoder)->reverse(30.0444, 31.2357));
    }

    public function test_the_street_is_the_nearest_segment_not_the_nearest_centre(): void
    {
        // «الطريق الساحلي الدولي» is ‪14‬ km of road inside the bbox, so its own
        // centre is kilometres from most of it. A nearest-centroid lookup names
        // it for half the town; a perpendicular one never does from here.
        $row = (new LocalGazetteerGeocoder)->reverse(31.4424, 31.5450);

        $this->assertSame('شارع أبو لقمان', $row['street']);
    }

    public function test_the_public_endpoint_answers_with_the_area(): void
    {
        $response = $this->getJson('/api/v1/geo/reverse?lat=31.4384&lng=31.5341')->assertOk();

        $this->assertSame('منطقة الكرنك', $response->json('data.neighborhood'));
        $this->assertSame('جمصة', $response->json('data.city'));
        $this->assertStringNotContainsString('الشاطئ', (string) $response->json('data.address'));
    }

    public function test_searching_a_street_by_name_never_leaves_the_machine(): void
    {
        $rows = (new LocalGazetteerGeocoder)->search('الكورنيش', 31.4453, 31.5344);

        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('الكورنيش', $rows[0]['name']);
    }
}
