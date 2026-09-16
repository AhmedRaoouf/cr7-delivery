<?php

namespace Tests\Feature;

use App\Services\Maps\ChainGeocoder;
use App\Services\Maps\Geocoder;
use App\Services\Maps\LocalGazetteerGeocoder;
use App\Services\Maps\OsmGeocoder;
use App\Services\Maps\PlaceResults;
use App\Services\Maps\PlatformPlaceGeocoder;
use Database\Seeders\GazetteerSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * D-127, second pass — near beats far, one line per road, and one source cannot
 * sink the rest.
 *
 * Measured against the local API on 2026-09-16 from the middle of جمصة, after the
 * first pass had made the folding work: «صيدلية» opened on three OpenStreetMap
 * nodes named exactly «صيدلية» in Cairo, ‪150‬ km away, above the four pharmacies
 * this platform delivers from at ‪2‬ km; «الكورنيش» listed one road five times.
 *
 * Every provider row here is what Nominatim actually answered that day —
 * `tests/Fixtures/nominatim-from-gamasa-2026-09-16.json` — and every place sits at
 * its real coordinates, so the distances are the ones a customer would see.
 */
class GeoSearchRankingTest extends TestCase
{
    use RefreshDatabase;

    /** Where the measurement was taken: the middle of جمصة. */
    private const LAT = 31.4388;

    private const LNG = 31.5442;

    protected function setUp(): void
    {
        parent::setUp();

        // Never re-seeded here — see GeoSearchTest::setUp(): the seeder TRUNCATEs.
        if (! DB::table('gazetteer_points')->exists()) {
            $this->seed(GazetteerSeeder::class);
        }

        Cache::flush();
        Http::preventStrayRequests();
        config([
            'maps.osm.geocoding' => true,
            'maps.osm.nominatim' => 'https://nominatim.openstreetmap.org',
            'maps.search.near_km' => 15,
            'maps.search.far_km' => 60,
            'maps.search.same_extent_km' => 3,
            'maps.search.local_is_enough' => 5,
        ]);

        $this->seedLocation();
    }

    // ------------------------------------------------------ the five queries

    public function test_pharmacy_opens_on_our_four_and_cairo_is_gone(): void
    {
        $this->places([
            'صيدلية عمرو' => [31.4457, 31.5188],
            'صيدلية النور' => [31.4489, 31.5121],
            'صيدلية الحياة' => [31.4441, 31.5209],
            'صيدلية جمصة' => [31.4512, 31.5165],
        ]);

        $rows = $this->search('صيدلية');

        $this->assertSame([
            'صيدلية الحياة', 'صيدلية عمرو', 'صيدلية جمصة', 'صيدلية النور',
            'صيدلية النيل', 'صيدلية دعوة الحق', 'صيدلية د. حمدى', 'صيدلية البسطويسى', 'صيدلية وادى النيل',
        ], array_column($rows, 'name'));

        $this->assertSame(['place', 'place', 'place', 'place'], array_column(array_slice($rows, 0, 4), 'kind'));
        // The three nodes named «صيدلية» and nothing else, ‪150‬–‪165‬ km off.
        $this->assertNotContains('صيدلية', array_column($rows, 'name'));
        // Nothing past the far cut-off while something is near.
        $this->assertLessThanOrEqual(60_000, max(array_column($rows, 'distance_m')));
        $this->assertNearBeforeFar($rows);
    }

    public function test_al_andalus_opens_on_the_area_and_lists_the_street_once(): void
    {
        $rows = $this->search('الاندلس');

        $this->assertSame(
            [['منطقة الأندلس', 'area', 'local'], ['شارع الأندلس', 'street', 'local'], ['الاندلس', 'poi', 'osm']],
            array_map(static fn (array $r): array => [$r['name'], $r['kind'], $r['source']], $rows),
        );

        // Nominatim's own «منطقة الاندلس» and «شارع الاندلس» ‪158‬ m from ours are the
        // same area and the same road; «شارع الاندلس» ‪21‬ km away shares a name with
        // one that is near and is not listed either.
        $this->assertSame(537, $rows[0]['distance_m']);
        $this->assertNearBeforeFar($rows);
    }

    public function test_the_corniche_is_one_line_however_many_ways_osm_splits_it_into(): void
    {
        $this->places(['كافيه الكورنيش' => [31.4536, 31.5174]]);

        $rows = $this->search('الكورنيش');

        // Eight OSM ways came back: five in جمصة, three in رأس البر.
        $this->assertSame(
            [['كافيه الكورنيش', 'place', 'places'], ['شارع الكورنيش', 'street', 'local']],
            array_map(static fn (array $r): array => [$r['name'], $r['kind'], $r['source']], $rows),
        );
    }

    public function test_damietta_lists_our_places_before_the_governorate_twenty_km_off(): void
    {
        $this->places([
            'سعد الدمياطي' => [31.4028, 31.4738],
            'صواني الدمياطي' => [31.3868, 31.4795],
        ]);

        $rows = $this->search('دمياط');

        $this->assertSame(
            [['سعد الدمياطي', 'place'], ['صواني الدمياطي', 'place'], ['دمياط', 'city']],
            array_map(static fn (array $r): array => [$r['name'], $r['kind']], $rows),
        );
        $this->assertNearBeforeFar($rows);
    }

    public function test_mansoura_from_gamasa_still_finds_the_city(): void
    {
        $rows = $this->search('المنصورة');

        $this->assertSame(
            [['جنة المنصورة الجديدة', 'area', 'local'], ['المنصورة', 'city', 'osm']],
            array_map(static fn (array $r): array => [$r['name'], $r['kind'], $r['source']], $rows),
        );
        $this->assertGreaterThan(40_000, $rows[1]['distance_m']);
    }

    // ------------------------------------------------------------- the rules

    public function test_when_nothing_is_near_far_rows_are_kept_however_far(): void
    {
        $this->fakeNominatim(['الإسكندرية' => [
            ['name' => 'الإسكندرية', 'lat' => '31.1991806', 'lon' => '29.8951716', 'category' => 'place', 'type' => 'city', 'addresstype' => 'city', 'address' => []],
            ['name' => 'مكتبة الإسكندرية', 'lat' => '31.2089', 'lon' => '29.9092', 'category' => 'amenity', 'type' => 'library', 'addresstype' => 'amenity', 'address' => ['road' => 'طريق الجيش']],
        ]]);

        $rows = $this->search('الإسكندرية', fake: false);

        $this->assertSame(['الإسكندرية', 'مكتبة الإسكندرية'], array_column($rows, 'name'));
        $this->assertGreaterThan(150_000, $rows[0]['distance_m']);
    }

    public function test_a_generic_poi_is_kept_near_and_its_name_is_no_exact_match(): void
    {
        $this->places(['صيدلية الحياة' => [31.4441, 31.5209]]);
        $this->fakeNominatim(['صيدلية' => [
            ['name' => 'صيدلية', 'lat' => '31.4400', 'lon' => '31.5460', 'category' => 'amenity', 'type' => 'pharmacy', 'addresstype' => 'amenity', 'address' => ['road' => 'شارع آمون', 'neighbourhood' => 'منطقة الكرنك']],
            ['name' => 'صيدلية', 'lat' => '30.1026073', 'lon' => '31.2609597', 'category' => 'amenity', 'type' => 'pharmacy', 'addresstype' => 'amenity', 'address' => ['road' => 'شارع علي حسن']],
        ]]);

        $rows = $this->search('صيدلية', fake: false);

        // Ours first although «صيدلية» is, letter for letter, what was typed.
        $this->assertSame(['صيدلية الحياة', 'صيدلية'], array_column($rows, 'name'));
        // The near one stays, and its address line is what tells it apart.
        $this->assertSame('صيدلية، شارع آمون، منطقة الكرنك', $rows[1]['address']);
        $this->assertLessThan(1_000, $rows[1]['distance_m']);
    }

    public function test_a_road_is_one_line_even_when_its_ends_are_further_apart_than_the_radius(): void
    {
        // Four ways of one road, ‪2.2‬ km apart each — ‪6.6‬ km end to end.
        $ways = array_map(static fn (int $i): array => [
            'name' => 'شارع البحر', 'address' => 'شارع البحر', 'lat' => 31.4400, 'lng' => 31.5000 + 0.023 * $i,
            'kind' => 'street', 'source' => 'osm',
        ], [0, 1, 2, 3]);

        $this->assertCount(1, PlaceResults::merge($ways, 'شارع البحر', self::LAT, self::LNG, 10));
    }

    public function test_two_shops_of_one_name_a_block_apart_stay_two(): void
    {
        $shops = [
            ['name' => 'فول وفلافل', 'address' => 'أ', 'lat' => 31.4400, 'lng' => 31.5440, 'kind' => 'poi', 'source' => 'osm'],
            ['name' => 'فول وفلافل', 'address' => 'ب', 'lat' => 31.4400, 'lng' => 31.5460, 'kind' => 'poi', 'source' => 'osm'],
        ];

        $this->assertCount(2, PlaceResults::merge($shops, 'فول', self::LAT, self::LNG, 10));
    }

    public function test_the_local_row_wins_a_duplicate_even_when_the_provider_row_is_nearer(): void
    {
        $rows = PlaceResults::merge([
            ['name' => 'شارع الأندلس', 'address' => 'x', 'lat' => 31.4430, 'lng' => 31.5460, 'kind' => 'street', 'source' => 'osm'],
            ['name' => 'شارع الأندلس', 'address' => 'y', 'lat' => 31.4460, 'lng' => 31.5440, 'kind' => 'street', 'source' => 'local'],
        ], 'الاندلس', self::LAT, self::LNG, 10);

        $this->assertSame(['local'], array_column($rows, 'source'));
    }

    // ----------------------------------------------------------- isolation

    public function test_a_source_that_throws_does_not_take_the_others_down(): void
    {
        Log::spy();
        $this->places(['صيدلية الحياة' => [31.4441, 31.5209]]);
        $this->fakeNominatim();

        // What the web stream saw before the migration ran: `Unknown column
        // 'name_normalized'`, a ‪500‬ for the whole merged search.
        $this->bind([new PlatformPlaceGeocoder, $this->throwingLocal(), new OsmGeocoder]);

        $response = $this->getJson($this->url('صيدلية'))
            ->assertOk()
            ->assertJsonPath('degraded', false);

        $sources = array_unique(array_column($response->json('data'), 'source'));
        sort($sources);
        $this->assertSame(['osm', 'places'], $sources);

        Log::shouldHaveReceived('error')->withArgs(
            static fn (string $message, array $context): bool => $context['source'] === 'local'
                && $context['call'] === 'search'
                && str_contains($context['message'], 'name_normalized'),
        );
    }

    public function test_every_source_failing_is_degraded_and_never_a_500(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

        $places = new class extends PlatformPlaceGeocoder
        {
            public function search(string $query, ?float $lat = null, ?float $lng = null, string $language = 'ar', ?string $country = null, int $limit = 10): array
            {
                throw new RuntimeException('connection refused');
            }
        };

        // Even asking whether the local tables are there throws.
        $local = new class extends LocalGazetteerGeocoder
        {
            public function isConfigured(): bool
            {
                throw new RuntimeException("Table 'gazetteer_points' doesn't exist");
            }
        };

        $this->bind([$places, $local, new OsmGeocoder]);

        $this->getJson($this->url('صيدلية'))
            ->assertOk()
            ->assertJsonPath('degraded', true)
            ->assertJsonCount(0, 'data');

        Log::shouldHaveReceived('error')->withArgs(static fn (string $m, array $c): bool => $c['source'] === 'local' && $c['call'] === 'isConfigured');
        Log::shouldHaveReceived('error')->withArgs(static fn (string $m, array $c): bool => $c['source'] === 'places');
    }

    public function test_a_source_throwing_beside_a_provider_that_found_nothing_is_not_degraded(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $this->bind([$this->throwingLocal(), new OsmGeocoder]);

        // Nominatim searched and had nothing: that is a real «لا توجد نتائج».
        $this->getJson($this->url('لا يوجد شيء بهذا الاسم'))
            ->assertOk()
            ->assertJsonPath('degraded', false)
            ->assertJsonCount(0, 'data');
    }

    public function test_reverse_skips_a_source_that_throws(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'name' => null,
            'address' => ['road' => 'شارع آمون', 'neighbourhood' => 'منطقة الكرنك'],
        ])]);

        $local = new class extends LocalGazetteerGeocoder
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function reverse(float $lat, float $lng, string $language = 'ar'): ?array
            {
                throw new RuntimeException('deadlock');
            }
        };

        $this->bind([$local, new OsmGeocoder]);

        $this->getJson('/api/v1/geo/reverse?lat=31.4388&lng=31.5442')
            ->assertOk()
            ->assertJsonPath('data.address', 'شارع آمون، منطقة الكرنك');
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  bool  $fake  answer from the captured fixture
     * @return list<array<string, mixed>>
     */
    private function search(string $query, bool $fake = true): array
    {
        if ($fake) {
            $this->fakeNominatim();
        }

        $this->bind([new PlatformPlaceGeocoder, new LocalGazetteerGeocoder, new OsmGeocoder]);

        return $this->getJson($this->url($query))
            ->assertOk()
            ->assertJsonPath('degraded', false)
            ->json('data');
    }

    private function url(string $query): string
    {
        return '/api/v1/geo/search?q='.urlencode($query).'&lat='.self::LAT.'&lng='.self::LNG;
    }

    /** @param array<string, list<array<string, mixed>>>|null $answers by `q`; the captured fixture when null */
    private function fakeNominatim(?array $answers = null): void
    {
        $answers ??= json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/nominatim-from-gamasa-2026-09-16.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        Http::fake(['nominatim.openstreetmap.org/*' => static fn (Request $request) => Http::response($answers[$request['q']] ?? [])]);
    }

    /** @param list<Geocoder> $geocoders */
    private function bind(array $geocoders): void
    {
        $this->app->instance(Geocoder::class, new ChainGeocoder($geocoders));
    }

    /** @param array<string, array{0: float, 1: float}> $places */
    private function places(array $places): void
    {
        foreach ($places as $name => [$lat, $lng]) {
            $this->makeRestaurant($name, $lat, $lng);
        }
    }

    private function throwingLocal(): LocalGazetteerGeocoder
    {
        return new class extends LocalGazetteerGeocoder
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function search(string $query, ?float $lat = null, ?float $lng = null, string $language = 'ar', ?string $country = null, int $limit = 10): array
            {
                throw new QueryException('mysql', 'select * from `gazetteer_areas` where name_normalized like ?', [], new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'name_normalized' in 'where clause'"));
            }
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertNearBeforeFar(array $rows): void
    {
        $seenFar = false;

        foreach ($rows as $row) {
            $near = $row['distance_m'] <= 15_000;
            $this->assertFalse($seenFar && $near, "«{$row['name']}» is near and was listed after a far row.");
            $seenFar = $seenFar || ! $near;
        }
    }
}
