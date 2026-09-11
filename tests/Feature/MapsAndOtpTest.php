<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreCategory;
use App\Services\Maps\ChainGeocoder;
use App\Services\Maps\Geocoder;
use App\Services\Maps\MapboxGeocoder;
use App\Services\Maps\NullGeocoder;
use App\Services\Maps\OsmGeocoder;
use App\Support\MapStyles;
use App\Support\Otp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two things a staging server cannot run without: a map it can draw and a
 * code somebody can sign in with.
 */
class MapsAndOtpTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ map styles

    public function test_config_falls_back_to_openstreetmap_when_nothing_is_configured(): void
    {
        config(['maps.provider' => 'mapbox', 'maps.mapbox.token' => null, 'maps.carto.api_key' => null]);

        $maps = MapStyles::payload();

        $this->assertSame('osm', $maps['provider']);
        $this->assertStringContainsString('tile.openstreetmap.org', $maps['tile_url_light']);
        // OSM has no dark set, so the client is told to invert the light one.
        $this->assertTrue($maps['invert_dark']);
        $this->assertSame(256, $maps['tile_size']);
        $this->assertSame(0, $maps['zoom_offset']);
        // Zoom 20 answers 400 on this render, and a client that asks for it
        // draws a grey square over the one street the customer was reading.
        $this->assertSame(19, $maps['max_zoom']);
        $this->assertSame('© OpenStreetMap', $maps['attribution']);
        // D-84 — geocoding is no longer a property of the **tile** provider.
        // With no Mapbox token the platform binds `OsmGeocoder`, so the client
        // is told to go on asking for street names; it used to be told `false`
        // on exactly the deployments that needed Nominatim most.
        $this->assertTrue($maps['geocoding']);
        // No shard placeholder, so no list to fill it from.
        $this->assertSame([], $maps['subdomains']);
    }

    public function test_osm_is_still_selectable_and_still_returns_openstreetmap(): void
    {
        config(['maps.provider' => 'osm', 'maps.mapbox.token' => 'pk.test-token', 'maps.carto.api_key' => 'ck.test']);

        $maps = MapStyles::payload();

        $this->assertSame('osm', $maps['provider']);
        $this->assertStringContainsString('tile.openstreetmap.org', $maps['tile_url_light']);
        $this->assertSame($maps['tile_url_light'], $maps['tile_url_dark']);
        $this->assertTrue($maps['invert_dark']);
    }

    public function test_carto_is_published_when_a_key_is_configured(): void
    {
        config(['maps.provider' => 'carto', 'maps.mapbox.token' => null, 'maps.carto.api_key' => 'ck.test-key']);

        $maps = MapStyles::payload();

        $this->assertSame('carto', $maps['provider']);
        $this->assertStringContainsString('rastertiles/voyager', $maps['tile_url_light']);
        $this->assertStringContainsString('rastertiles/dark_all', $maps['tile_url_dark']);
        $this->assertStringContainsString('api_key=ck.test-key', $maps['tile_url_light']);
        // The half of the operator's complaint that is not label density: CARTO
        // ships a real dark style, so nothing is inverted over it. An inversion
        // greys every label it passes through.
        $this->assertFalse($maps['invert_dark']);
        // 18 is where the map stopped resolving street detail.
        $this->assertSame(20, $maps['max_zoom']);
        $this->assertSame(256, $maps['tile_size']);
        $this->assertSame(0, $maps['zoom_offset']);
        // A licence requirement, not a nicety.
        $this->assertSame('© OpenStreetMap © CARTO', $maps['attribution']);
        $this->assertSame('https://carto.com/attributions', $maps['attribution_url']);
    }

    public function test_carto_leaves_its_shard_and_retina_markers_for_the_client(): void
    {
        config(['maps.provider' => 'carto', 'maps.carto.api_key' => 'ck.test-key']);

        $maps = MapStyles::payload();

        // `{s}` and `{r}` are the client's to fill, exactly like `{z}/{x}/{y}`:
        // the shard from the list published beside them, and `@2x` only on a
        // high-density screen. A server that expanded either would ship one
        // hostname to every phone and retina bytes to phones that cannot use
        // them.
        $this->assertStringContainsString('{z}/{x}/{y}', $maps['tile_url_light']);
        $this->assertStringContainsString('{s}.', $maps['tile_url_light']);
        $this->assertStringContainsString('{y}{r}.png', $maps['tile_url_light']);
        $this->assertSame(['a', 'b', 'c', 'd'], $maps['subdomains']);
    }

    public function test_a_keyless_carto_is_never_served(): void
    {
        // `basemaps.cartocdn.com` answers 200 without a key — with «API KEY
        // REQUIRED» stamped diagonally across the tile. Selecting it on a
        // status check would put an advert on every map in the app.
        config(['maps.provider' => 'carto', 'maps.carto.api_key' => null, 'maps.mapbox.token' => null]);

        $maps = MapStyles::payload();

        $this->assertSame('osm', $maps['provider']);
        $this->assertStringNotContainsString('cartocdn', $maps['tile_url_light']);
    }

    public function test_a_token_still_wins_over_the_free_basemap(): void
    {
        config(['maps.provider' => 'mapbox', 'maps.mapbox.token' => 'pk.test-token']);

        $this->assertSame('mapbox', MapStyles::payload()['provider']);
    }

    public function test_config_publishes_mapbox_tiles_when_a_token_is_set(): void
    {
        config([
            'maps.provider' => 'mapbox',
            'maps.mapbox.token' => 'pk.test-token',
            'maps.mapbox.style_light' => 'mapbox/streets-v12',
            'maps.mapbox.style_dark' => 'mapbox/dark-v11',
        ]);

        $maps = MapStyles::payload();

        $this->assertSame('mapbox', $maps['provider']);
        $this->assertStringContainsString('mapbox/streets-v12', $maps['tile_url_light']);
        $this->assertStringContainsString('mapbox/dark-v11', $maps['tile_url_dark']);
        $this->assertStringContainsString('access_token=pk.test-token', $maps['tile_url_light']);

        // The placeholders are the client's to fill; a server that expanded them
        // would ship one tile of جمصة to every screen in the app.
        $this->assertStringContainsString('{z}/{x}/{y}', $maps['tile_url_light']);

        // A real dark style must not be inverted on top.
        $this->assertFalse($maps['invert_dark']);
        $this->assertTrue($maps['geocoding']);
        $this->assertSame(512, $maps['tile_size']);
        $this->assertSame(-1, $maps['zoom_offset']);
        // Raised from 18 — the level the map used to stop resolving streets at.
        $this->assertSame(20, $maps['max_zoom']);
    }

    public function test_the_config_endpoint_carries_the_maps_block(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.maps.provider', 'mapbox')
            ->assertJsonStructure(['data' => ['maps' => ['tile_url_light', 'tile_url_dark', 'tile_size', 'zoom_offset', 'attribution', 'default_center']]]);
    }

    // ---------------------------------------------------------------- places

    public function test_geo_places_names_the_shops_around_a_point(): void
    {
        Cache::flush();
        $this->seedLocation();

        $category = StoreCategory::query()->create([
            'slug' => 'restaurants',
            'name_ar' => 'مطاعم',
            'name_en' => 'Restaurants',
            'color' => '#F5C518',
            'icon' => 'utensils',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $near = $this->makeRestaurant('سندوتش عمر', 31.3841, 31.4841);
        $near->update(['store_category_id' => $category->id]);
        // Damietta, about 25 km up the coast — inside no sane map viewport.
        $this->makeRestaurant('سعد الدمياطي', 31.5600, 31.7500);

        $response = $this->getJson('/api/v1/geo/places?lat=31.3840&lng=31.4840&radius_km=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'سندوتش عمر')
            ->assertJsonPath('data.0.store_category.slug', 'restaurants')
            ->assertJsonPath('data.0.store_category.color', '#F5C518')
            ->assertJsonPath('data.0.store_category.icon', 'utensils');

        // The pin needs a point, and the label needs to know how far it is so
        // the client can cull the far ones rather than stack them.
        $this->assertSame(31.3841, $response->json('data.0.lat'));
        $this->assertSame(31.4841, $response->json('data.0.lng'));
        $this->assertLessThan(0.2, $response->json('data.0.distance_km'));
    }

    public function test_geo_places_is_public_and_hides_a_deactivated_shop(): void
    {
        Cache::flush();
        $this->seedLocation();

        $this->makeRestaurant('كنج بروست', 31.3842, 31.4842)->update(['is_active' => false]);

        // No token, no `X-App`: this is the same catalogue a signed-out browse
        // already sees, narrowed to a point.
        $this->getJson('/api/v1/geo/places?lat=31.3840&lng=31.4840')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_geo_places_refuses_a_point_it_cannot_read(): void
    {
        $this->getJson('/api/v1/geo/places?lat=999&lng=31.4840')->assertStatus(422);
        $this->getJson('/api/v1/geo/places')->assertStatus(422);
    }

    // ------------------------------------------------------------- geocoding

    public function test_reverse_geocoding_answers_nulls_rather_than_failing_without_a_provider(): void
    {
        $this->app->instance(Geocoder::class, new NullGeocoder);

        $this->getJson('/api/v1/geo/reverse?lat=31.4472&lng=31.5142')
            ->assertOk()
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.city', null);
    }

    public function test_reverse_geocoding_returns_the_street_line(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Cache::flush();
        Http::fake(['api.mapbox.com/*' => Http::response([
            'features' => [[
                'text' => 'شارع البحر',
                'place_name' => 'شارع البحر، جمصة، الدقهلية',
                'context' => [
                    ['id' => 'neighborhood.1', 'text' => 'الكورنيش'],
                    ['id' => 'place.2', 'text' => 'جمصة'],
                ],
            ]],
        ])]);
        $this->app->instance(Geocoder::class, new MapboxGeocoder);

        $this->getJson('/api/v1/geo/reverse?lat=31.4472&lng=31.5142')
            ->assertOk()
            // D-84 — the composed line, not `place_name`: «شارع البحر، جمصة،
            // الدقهلية، مصر» wraps to three lines to say what this says in one,
            // and the governorate is not an address a captain rides to.
            ->assertJsonPath('data.address', 'شارع البحر، الكورنيش')
            ->assertJsonPath('data.neighborhood', 'الكورنيش')
            ->assertJsonPath('data.city', 'جمصة');
    }

    public function test_a_second_lookup_of_the_same_point_costs_no_request(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Cache::flush();
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => [['place_name' => 'جمصة']]])]);
        $geocoder = new MapboxGeocoder;

        $geocoder->reverse(31.4472, 31.5142);
        $geocoder->reverse(31.4472, 31.5142);

        // The pin moved less than 11 m, which is the whole reason the key is
        // rounded: a dragged pin must not bill a request per frame.
        Http::assertSentCount(1);
    }

    public function test_a_provider_outage_is_a_missing_label_not_an_error(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Cache::flush();
        Http::fake(['api.mapbox.com/*' => Http::response('gateway down', 502)]);
        $this->app->instance(Geocoder::class, new MapboxGeocoder);

        $this->getJson('/api/v1/geo/reverse?lat=31.4472&lng=31.5142')
            ->assertOk()
            ->assertJsonPath('data.address', null);
    }

    /**
     * The defect that reached production on 2026-09-11, pinned at the layer it
     * happened in: the **request**, not the response.
     *
     * Every geocoding test above fakes what Mapbox sends back, so all of them
     * passed while the deployed server was answering `null` for every pin —
     * Mapbox was refusing the query with «limit must be combined with a single
     * type parameter when reverse geocoding» and a faked 200 can never say so.
     */
    public function test_the_reverse_request_never_carries_a_limit(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Cache::flush();
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => [['text' => 'شارع البحر']]])]);

        (new MapboxGeocoder)->reverse(31.4472, 31.5142);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            $this->assertArrayNotHasKey('limit', $query, 'Mapbox answers 422 to a reverse lookup carrying `limit` with more than one type.');
            $this->assertSame('address,poi,neighborhood,locality,place', $query['types'] ?? null);

            return true;
        });
    }

    public function test_a_refused_mapbox_query_falls_through_to_the_keyless_geocoder(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token', 'maps.osm.geocoding' => true]);
        Cache::flush();

        Http::fake([
            'api.mapbox.com/*' => Http::response(['message' => 'limit must be combined with a single type parameter when reverse geocoding'], 422),
            'nominatim.openstreetmap.org/*' => Http::response([
                'name' => null,
                'address' => [
                    'road' => 'شارع الكورنيش',
                    'neighbourhood' => 'منطقة العاشر من رمضان',
                    'city' => 'جمصة',
                ],
                'display_name' => 'شارع الكورنيش, جمصة, الدقهلية, مصر',
            ]),
        ]);

        $this->app->instance(Geocoder::class, new ChainGeocoder([new MapboxGeocoder, new OsmGeocoder]));

        // A token the operator is paying for is a preference. When the provider
        // holding it refuses the query, the pin still gets a name.
        $this->getJson('/api/v1/geo/reverse?lat=31.4472&lng=31.5142')
            ->assertOk()
            ->assertJsonPath('data.address', 'شارع الكورنيش، منطقة العاشر من رمضان')
            ->assertJsonPath('data.street', 'شارع الكورنيش')
            ->assertJsonPath('data.city', 'جمصة');
    }

    public function test_the_chain_prefers_the_first_provider_that_answers(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token', 'maps.osm.geocoding' => true]);
        Cache::flush();

        Http::fake([
            'api.mapbox.com/*' => Http::response(['features' => [['text' => 'شارع البحر']]]),
            'nominatim.openstreetmap.org/*' => Http::response(['address' => ['road' => 'شارع آخر']]),
        ]);

        $row = (new ChainGeocoder([new MapboxGeocoder, new OsmGeocoder]))->reverse(31.4472, 31.5142);

        $this->assertSame('شارع البحر', $row['address'] ?? null);
        // The fallback is a fallback, not a second opinion: nothing is sent to
        // Nominatim while the paid provider is answering.
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'nominatim'));
    }

    public function test_forward_search_is_biased_to_the_launch_city(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Cache::flush();
        Http::fake(['api.mapbox.com/*' => Http::response([
            'features' => [[
                'text' => 'كورنيش جمصة',
                'place_name' => 'كورنيش جمصة، الدقهلية',
                'center' => [31.5142, 31.4472],
            ]],
        ])]);
        $this->app->instance(Geocoder::class, new MapboxGeocoder);

        $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'كورنيش جمصة')
            ->assertJsonPath('data.0.lat', 31.4472);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'proximity=31.5142%2C31.4472')
            && str_contains($request->url(), 'country=eg'));
    }

    public function test_a_one_letter_query_is_refused_before_it_costs_a_request(): void
    {
        $this->getJson('/api/v1/geo/search?q='.urlencode('ا'))
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------- OTP

    public function test_static_mode_pins_both_code_lengths(): void
    {
        config(['otp.static' => true, 'otp.static_code' => '123456', 'otp.static_order_code' => '1234']);

        $this->assertSame('123456', Otp::authCode());
        $this->assertSame('1234', Otp::orderCode());
    }

    public function test_a_misconfigured_code_is_padded_to_the_length_the_validator_demands(): void
    {
        // `digits:6` would reject a 2-digit code on the way back in, locking
        // everyone out of an account nobody could report as broken.
        config(['otp.static' => true, 'otp.static_code' => '12', 'otp.static_order_code' => 'ab7']);

        $this->assertSame('000012', Otp::authCode());
        $this->assertSame('0007', Otp::orderCode());
    }

    public function test_switching_static_mode_off_restores_random_codes(): void
    {
        config(['otp.static' => false]);

        $this->assertMatchesRegularExpression('/^\d{6}$/', Otp::authCode());
        $this->assertMatchesRegularExpression('/^\d{4}$/', Otp::orderCode());
        $this->assertNull(Otp::echoable());
    }

    public function test_a_random_code_is_never_echoed_back_to_its_own_requester(): void
    {
        // Echoing a random code hands the account to whoever asked for it, so
        // the echo is tied to the code being static — not to the flag alone.
        config(['otp.static' => false, 'otp.echo_in_response' => true]);

        $this->assertNull(Otp::echoable());
    }

    public function test_send_otp_hands_back_the_staging_code(): void
    {
        config(['otp.static' => true, 'otp.echo_in_response' => true, 'otp.static_code' => '123456']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01000000123'])
            ->assertOk()
            ->assertJsonPath('data.static_otp', '123456');
    }

    public function test_send_otp_says_nothing_once_a_real_gateway_is_bound(): void
    {
        config(['otp.static' => false]);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01000000124'])
            ->assertOk()
            ->assertJsonMissingPath('data.static_otp');
    }

    public function test_the_staging_code_actually_signs_a_customer_in(): void
    {
        config(['otp.static' => true, 'otp.static_code' => '123456']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01000000125'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01000000125', 'code' => '123456'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_an_order_placed_in_static_mode_carries_the_fixed_pickup_code(): void
    {
        config(['otp.static' => true, 'otp.static_order_code' => '1234']);
        $this->seedLocation();

        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'ساندوتش', 40);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $product, 2]]);

        $this->actingAs($customer, 'sanctum')
            ->withHeader('X-App', 'app')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'payment_method' => 'cash',
            ], ['Idempotency-Key' => 'static-otp-test'])
            ->assertCreated();

        $this->assertSame('1234', Order::query()->firstOrFail()->getRawOriginal('pickup_otp'));
    }
}
