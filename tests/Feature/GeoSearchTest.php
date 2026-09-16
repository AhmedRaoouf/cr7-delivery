<?php

namespace Tests\Feature;

use App\Services\Maps\ChainGeocoder;
use App\Services\Maps\Geocoder;
use App\Services\Maps\LocalGazetteerGeocoder;
use App\Services\Maps\MapboxGeocoder;
use App\Services\Maps\NullGeocoder;
use App\Services\Maps\OsmGeocoder;
use App\Services\Maps\PlatformPlaceGeocoder;
use App\Support\ArabicSearch;
use App\Support\Geo;
use Database\Seeders\GazetteerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * D-127 — one search that finds what a person typed.
 *
 * Operator, 2026-09-16: «الخريطه عموما في التطبيق كله والويب عايز البحث يبقا احسن
 * في مناطق كتير مش بتيجي وخلي ال requests بتاعه الخريطه مقتصده».
 *
 * Every defect behind «مناطق كتير مش بتيجي» answered ‪200‬ with an empty or useless
 * list, which is why none of the previous geocoding tests saw it: they asserted a
 * non-empty answer, and a non-empty answer is what eight identical rows are. So
 * these assert the thing the customer would have noticed — that the row they
 * meant is in the list, once, where they would look for it.
 */
class GeoSearchTest extends TestCase
{
    use RefreshDatabase;

    /** The middle of جمصة. */
    private const GAMASA = [31.4453, 31.5344];

    /** الإسكندرية — ‪200‬ km from anything the address book holds. */
    private const ALEXANDRIA = [31.2001, 29.9187];

    protected function setUp(): void
    {
        parent::setUp();

        // The migrations seed the gazetteer, so these tests read exactly what a
        // deployed database holds — and they do **not** re-seed it. The seeder
        // `TRUNCATE`s, and on MySQL a `TRUNCATE` ends the transaction
        // `RefreshDatabase` wraps each test in: every row a test wrote after it
        // would be committed for good and inherited by every later test class.
        // Only a database that somehow lost its rows is re-seeded.
        if (! DB::table('gazetteer_points')->exists()) {
            $this->seed(GazetteerSeeder::class);
        }

        Cache::flush();
        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------ folding

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function typedWithoutTheHamza(): array
    {
        // What the ordinary Arabic keyboard produces, against what the gazetteer
        // stores. Both searches answered nothing before D-127.
        return [
            'الأندلس' => ['الاندلس', 'منطقة الأندلس'],
            'إيزيس' => ['ايزيس', 'منطقة إيزيس'],
            'أبو سمبل' => ['ابو سمبل', 'منطقة أبو سمبل'],
            'أبو عيش' => ['ابو عيش', 'شارع أبو عيش'],
        ];
    }

    #[DataProvider('typedWithoutTheHamza')]
    public function test_a_name_typed_without_its_hamza_is_still_found(string $typed, string $stored): void
    {
        $rows = (new LocalGazetteerGeocoder)->search($typed, ...self::GAMASA);

        $this->assertContains($stored, array_column($rows, 'name'), "«{$typed}» must find «{$stored}».");
    }

    public function test_the_endpoint_finds_al_andalus_the_way_it_is_typed(): void
    {
        $this->bindChain([new LocalGazetteerGeocoder]);

        $response = $this->getJson('/api/v1/geo/search?q='.urlencode('الاندلس').'&lat=31.4453&lng=31.5344')
            ->assertOk()
            ->assertJsonPath('degraded', false);

        $area = collect($response->json('data'))->firstWhere('name', 'منطقة الأندلس');

        $this->assertNotNull($area);
        $this->assertSame('area', $area['kind']);
        $this->assertSame('local', $area['source']);
        $this->assertIsInt($area['distance_m']);
        $this->assertArrayNotHasKey('place_id', $area);
    }

    public function test_a_percent_sign_typed_is_a_literal_not_a_wildcard(): void
    {
        // The old `\%` leaned on MySQL's default escape character and declared none.
        // Unescaped, `%%` is a wildcard that matches every one of the ‪6 384‬ rows.
        $this->assertSame([], (new LocalGazetteerGeocoder)->search('%%', ...self::GAMASA));
        $this->assertSame([], (new LocalGazetteerGeocoder)->search('__', ...self::GAMASA));
    }

    // --------------------------------------------------------- duplicates

    public function test_the_corniche_is_one_row_not_eight(): void
    {
        // `gazetteer_streets` is one row per OSM way, and «شارع الكورنيش» is ten of
        // them. `limit(8)` spent every slot on the same road.
        $rows = (new LocalGazetteerGeocoder)->search('الكورنيش', ...self::GAMASA);

        $this->assertCount(1, array_filter($rows, static fn (array $row): bool => $row['name'] === 'شارع الكورنيش'));
        $this->assertSame('شارع الكورنيش', $rows[0]['name']);
        $this->assertSame('street', $rows[0]['kind']);
        // ‪157‬ doors carry the name too. None of them is what was typed.
        $this->assertSame([], array_filter($rows, static fn (array $row): bool => $row['kind'] === 'address'));
    }

    public function test_a_street_is_placed_on_its_nearest_vertex_not_a_bbox_midpoint(): void
    {
        $rows = (new LocalGazetteerGeocoder)->search('كفر الوسطاني', ...self::GAMASA);
        $road = collect($rows)->firstWhere('kind', 'street');

        $this->assertNotNull($road);

        // Every vertex of every segment of that name, and the one nearest the pin.
        $vertices = DB::table('gazetteer_streets')
            ->where('name', $road['name'])
            ->pluck('geom')
            ->flatMap(static fn (string $geom): array => json_decode($geom, true));

        $nearest = $vertices
            ->sortBy(static fn (array $v): float => Geo::metres(self::GAMASA[0], self::GAMASA[1], (float) $v[0], (float) $v[1]))
            ->first();

        $this->assertEqualsWithDelta($nearest[0], $road['lat'], 0.0000001);
        $this->assertEqualsWithDelta($nearest[1], $road['lng'], 0.0000001);
        $this->assertCount(1, array_filter($rows, static fn (array $row): bool => $row['kind'] === 'street'), '‪32‬ segments, one row.');
    }

    // -------------------------------------------------------------- doors

    public function test_a_building_is_found_by_its_number(): void
    {
        // «منطقة ‪6أ‬» numbers its buildings and names almost none of its roads —
        // the number *is* the address. None of the ‪6 128‬ doors was searchable.
        $rows = (new LocalGazetteerGeocoder)->search('6175', ...self::GAMASA);

        $this->assertNotEmpty($rows);
        $this->assertSame('address', $rows[0]['kind']);
        $this->assertStringContainsString('6175', $rows[0]['name']);
        $this->assertStringContainsString('منطقة 6أ', $rows[0]['address']);
    }

    public function test_a_number_typed_with_its_area_finds_that_door_and_not_its_neighbours(): void
    {
        $doors = array_values(array_filter(
            (new LocalGazetteerGeocoder)->search('20 الاندلس', ...self::GAMASA),
            static fn (array $row): bool => $row['kind'] === 'address',
        ));

        $this->assertNotEmpty($doors);
        $this->assertContains("مبنى \u{202A}20\u{202C}، منطقة الأندلس", array_column($doors, 'address'));

        // Door ‪20‬ in the area and door ‪20‬ on «شارع الأندلس» both answer it. Door
        // ‪200‬ and ‪201‬ do not: a number typed in full beats one it merely starts.
        foreach ($doors as $door) {
            $this->assertStringContainsString("\u{202A}20\u{202C}", $door['name']);
        }
    }

    public function test_an_area_is_not_answered_with_a_list_of_its_buildings(): void
    {
        // «ايزيس» matches ‪54‬ doors through their area tag. «مبنى ‪17‬، مبنى ‪18‬ …» under
        // the area the customer named pushed the area itself off the screen.
        $rows = (new LocalGazetteerGeocoder)->search('ايزيس', ...self::GAMASA);

        $this->assertSame(['منطقة إيزيس'], array_column($rows, 'name'));
        $this->assertSame('area', $rows[0]['kind']);
    }

    public function test_an_area_known_only_to_its_doors_is_still_found_once(): void
    {
        // Three areas exist on doors and in no area row. Dropping door matches
        // wholesale would have made them unfindable.
        $rows = (new LocalGazetteerGeocoder)->search('جنة المنصورة', ...self::GAMASA);

        $this->assertSame(['جنة المنصورة الجديدة'], array_column($rows, 'name'));
        $this->assertSame('area', $rows[0]['kind']);
    }

    public function test_a_door_matched_through_its_street_does_not_offer_its_area(): void
    {
        $names = array_column((new LocalGazetteerGeocoder)->search('الكورنيش', ...self::GAMASA), 'name');

        foreach ($names as $name) {
            $this->assertStringContainsString('الكورنيش', $name);
        }
    }

    public function test_the_bidi_isolate_around_a_number_is_folded_away(): void
    {
        // Every number in an Arabic string here carries `U+202A … U+202C`; nobody
        // types them, so without this no such name ever matched exactly.
        // ى folds to ي as well; the isolates simply vanish.
        $this->assertSame('مبني 6175', ArabicSearch::normalize("مبنى \u{202A}6175\u{202C}"));
        $this->assertSame(0, ArabicSearch::tier("مبنى \u{202A}6175\u{202C}", 'مبنى 6175'));
    }

    public function test_every_seeded_row_carries_its_folded_name(): void
    {
        $this->assertSame(0, DB::table('gazetteer_areas')->whereNull('name_normalized')->count());
        $this->assertSame(0, DB::table('gazetteer_streets')->whereNull('name_normalized')->count());
        $this->assertSame(0, DB::table('gazetteer_points')->whereNull('label_normalized')->count());

        $andalus = DB::table('gazetteer_areas')->where('name', 'منطقة الأندلس')->first();
        $this->assertSame(ArabicSearch::normalize('منطقة الأندلس'), $andalus->name_normalized);
        $this->assertSame('منطقه الاندلس', $andalus->name_normalized);
    }

    // ----------------------------------------------------- our own places

    public function test_a_place_we_serve_outranks_a_street_and_carries_its_id(): void
    {
        $this->seedLocation();
        $cafe = $this->makeRestaurant('كافيه الكورنيش', 31.4460, 31.5350);

        $this->bindChain([new PlatformPlaceGeocoder, new LocalGazetteerGeocoder]);

        $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش').'&lat=31.4453&lng=31.5344')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'كافيه الكورنيش')
            ->assertJsonPath('data.0.kind', 'place')
            ->assertJsonPath('data.0.source', 'places')
            ->assertJsonPath('data.0.place_id', $cafe->id)
            ->assertJsonPath('data.1.name', 'شارع الكورنيش');
    }

    public function test_a_deactivated_place_is_not_offered(): void
    {
        $this->seedLocation();
        $this->makeRestaurant('سندوتش عمر', 31.4460, 31.5350)->update(['is_active' => false]);

        $this->assertSame([], (new PlatformPlaceGeocoder)->search('سندوتش عمر', ...self::GAMASA));
    }

    // ----------------------------------------------------------- the chain

    public function test_a_query_outside_gamasa_reaches_the_providers_and_gets_no_gamasa_rows(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => [[
            'text' => 'كورنيش الإسكندرية',
            'place_name' => 'كورنيش الإسكندرية، الإسكندرية',
            'place_type' => ['address'],
            'center' => [29.9200, 31.2010],
        ]]])]);

        $rows = (new ChainGeocoder([new LocalGazetteerGeocoder, new MapboxGeocoder]))
            ->search('الكورنيش', ...self::ALEXANDRIA);

        $this->assertSame(['mapbox'], array_values(array_unique(array_column($rows, 'source'))));
        $this->assertSame('كورنيش الإسكندرية', $rows[0]['name']);
        Http::assertSentCount(1);
    }

    public function test_a_weak_local_hit_no_longer_suppresses_the_providers(): void
    {
        // «ابو سمبل» matches exactly one local row. First-wins returned that one
        // row and never asked anybody else.
        config(['maps.mapbox.token' => 'pk.test-token']);
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => [[
            'text' => 'كافيه أبو سمبل',
            'place_name' => 'كافيه أبو سمبل، جمصة',
            'place_type' => ['poi'],
            'center' => [31.5600, 31.4700],
        ]]])]);

        $rows = (new ChainGeocoder([new LocalGazetteerGeocoder, new MapboxGeocoder]))
            ->search('ابو سمبل', ...self::GAMASA);

        $this->assertSame(['منطقة أبو سمبل', 'كافيه أبو سمبل'], array_column($rows, 'name'));
        Http::assertSentCount(1);
    }

    public function test_a_well_answered_local_query_spends_no_billed_request(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token', 'maps.search.local_is_enough' => 5]);
        Http::fake();

        // ‪81‬ street names start with it.
        $rows = (new ChainGeocoder([new LocalGazetteerGeocoder, new MapboxGeocoder]))
            ->search('شارع', ...self::GAMASA);

        $this->assertGreaterThanOrEqual(5, count($rows));
        Http::assertNothingSent();
    }

    public function test_the_limit_is_honoured_and_bounded(): void
    {
        $this->bindChain([new LocalGazetteerGeocoder]);

        $this->getJson('/api/v1/geo/search?q='.urlencode('شارع').'&limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/geo/search?q='.urlencode('شارع'))
            ->assertOk()
            ->assertJsonCount(10, 'data');

        $this->getJson('/api/v1/geo/search?q='.urlencode('شارع').'&limit=25')
            ->assertOk()
            ->assertJsonCount(25, 'data');

        $this->getJson('/api/v1/geo/search?q='.urlencode('شارع').'&limit=26')->assertStatus(422);
    }

    public function test_distance_is_null_without_a_point_and_metres_with_one(): void
    {
        $this->bindChain([new LocalGazetteerGeocoder]);

        $this->getJson('/api/v1/geo/search?q='.urlencode('الاندلس'))
            ->assertOk()
            ->assertJsonPath('data.0.distance_m', null);

        $withPoint = $this->getJson('/api/v1/geo/search?q='.urlencode('الاندلس').'&lat=31.4453&lng=31.5344')->assertOk();

        $this->assertIsInt($withPoint->json('data.0.distance_m'));
        $this->assertGreaterThan(0, $withPoint->json('data.0.distance_m'));
    }

    // ------------------------------------------------------------- failure

    public function test_every_provider_down_is_degraded_not_an_empty_town(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token', 'maps.osm.geocoding' => true]);
        Http::fake([
            'api.mapbox.com/*' => Http::response(['message' => 'Not Authorized - Invalid Token'], 401),
            'nominatim.openstreetmap.org/*' => Http::response('', 403),
        ]);

        $this->bindChain([new LocalGazetteerGeocoder, new MapboxGeocoder, new OsmGeocoder]);

        $this->getJson('/api/v1/geo/search?q='.urlencode('شارع').'&lat='.self::ALEXANDRIA[0].'&lng='.self::ALEXANDRIA[1])
            ->assertOk()
            ->assertJsonPath('degraded', true)
            ->assertJsonPath('meta.degraded', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_nothing_matching_is_not_degraded(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => []])]);

        $this->bindChain([new MapboxGeocoder]);

        $this->getJson('/api/v1/geo/search?q='.urlencode('لا يوجد مكان بهذا الاسم'))
            ->assertOk()
            ->assertJsonPath('degraded', false)
            ->assertJsonCount(0, 'data');
    }

    public function test_one_dead_provider_beside_a_live_one_is_not_degraded(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token', 'maps.osm.geocoding' => true]);
        Http::fake([
            'api.mapbox.com/*' => Http::response('gateway down', 502),
            'nominatim.openstreetmap.org/*' => Http::response([[
                'name' => 'كورنيش الإسكندرية',
                'lat' => '31.2010',
                'lon' => '29.9200',
                'addresstype' => 'road',
                'address' => ['road' => 'كورنيش الإسكندرية', 'city' => 'الإسكندرية'],
            ]]),
        ]);

        $this->bindChain([new MapboxGeocoder, new OsmGeocoder]);

        $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش').'&lat='.self::ALEXANDRIA[0].'&lng='.self::ALEXANDRIA[1])
            ->assertOk()
            ->assertJsonPath('degraded', false)
            ->assertJsonPath('data.0.source', 'osm')
            ->assertJsonPath('data.0.kind', 'street');
    }

    public function test_a_failed_search_is_not_cached_for_a_week(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        // `retry(1, …)` is one attempt in total, so one response per search.
        Http::fakeSequence('api.mapbox.com/*')
            ->push('gateway down', 502)
            ->push(['features' => [['text' => 'شارع البحر', 'place_name' => 'شارع البحر', 'center' => [31.5142, 31.4472]]]]);

        $geocoder = new MapboxGeocoder;

        $this->assertSame([], $geocoder->search('شارع البحر'));
        $this->assertTrue($geocoder->lastCallFailed());

        // The outage is over. A cached `[]` would say «لا توجد نتائج» until next week.
        $rows = $geocoder->search('شارع البحر');

        $this->assertFalse($geocoder->lastCallFailed());
        $this->assertSame('شارع البحر', $rows[0]['name'] ?? null);
    }

    public function test_a_null_geocoder_is_never_degraded(): void
    {
        $this->app->instance(Geocoder::class, new NullGeocoder);

        $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))
            ->assertOk()
            ->assertJsonPath('degraded', false)
            ->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ requests

    public function test_mapbox_is_asked_for_regions_and_districts_in_the_requested_country(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token', 'maps.country' => 'eg']);
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => []])]);

        $this->bindChain([new MapboxGeocoder]);

        $this->getJson('/api/v1/geo/search?q='.urlencode('الدقهلية'))->assertOk();
        $this->getJson('/api/v1/geo/search?q='.urlencode('الرياض').'&country=SA')->assertOk();

        $queries = collect(Http::recorded())->map(static function (array $pair): array {
            parse_str((string) parse_url((string) $pair[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        });

        $this->assertSame('address,poi,neighborhood,locality,place,district,region', $queries[0]['types']);
        $this->assertSame('eg', $queries[0]['country'], 'The configured country when none is asked for.');
        $this->assertSame('sa', $queries[1]['country']);
    }

    public function test_a_country_that_is_not_a_code_is_refused(): void
    {
        $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش').'&country=egypt')->assertStatus(422);
    }

    // --------------------------------------------------------------- cache

    public function test_local_answers_are_cached_and_a_reseed_invalidates_them(): void
    {
        $geocoder = new LocalGazetteerGeocoder;
        $this->assertContains('شارع الأندلس', array_column($geocoder->search('الاندلس', ...self::GAMASA), 'name'));

        // Pull the street out from under the cache — an `UPDATE`, which the test's
        // transaction rolls back. A cached answer still has it, which is the point
        // of caching a search that runs on every keystroke. (A street, not the
        // area: an area is also answered by the doors that carry its name.)
        DB::table('gazetteer_streets')->where('name', 'شارع الأندلس')->update(['name_normalized' => 'xxx']);
        $this->assertContains('شارع الأندلس', array_column($geocoder->search('الاندلس', ...self::GAMASA), 'name'));

        // A re-seed drops the stamp every cached key carries.
        Cache::forget(LocalGazetteerGeocoder::STAMP_KEY);
        $this->assertNotContains('شارع الأندلس', array_column($geocoder->search('الاندلس', ...self::GAMASA), 'name'));
    }

    public function test_an_empty_gazetteer_is_not_remembered_as_empty(): void
    {
        // `DELETE`, never the seeder's `TRUNCATE`: this has to roll back.
        $door = (array) DB::table('gazetteer_points')->first();
        DB::table('gazetteer_points')->delete();
        Cache::flush();

        $geocoder = new LocalGazetteerGeocoder;
        $this->assertFalse($geocoder->isConfigured());

        // Seeded a minute later, after a lookup had already cached «no rows» —
        // which `Cache::remember()` used to keep for an hour.
        Cache::put(LocalGazetteerGeocoder::READY_KEY, false, now()->addHour());
        DB::table('gazetteer_points')->insert($door);

        $this->assertTrue($geocoder->isConfigured());
    }

    /** @param list<Geocoder> $geocoders */
    private function bindChain(array $geocoders): void
    {
        $this->app->instance(Geocoder::class, new ChainGeocoder($geocoders));
    }
}
