<?php

namespace Tests\Feature;

use App\Services\Maps\Geocoder;
use App\Services\Maps\MapboxGeocoder;
use App\Services\Maps\NullGeocoder;
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

    public function test_config_falls_back_to_osm_when_no_token_is_set(): void
    {
        config(['maps.mapbox.token' => null]);

        $maps = MapStyles::payload();

        $this->assertSame('osm', $maps['provider']);
        $this->assertStringContainsString('tile.openstreetmap.org', $maps['tile_url_light']);
        // OSM has no dark set, so the client is told to invert the light one.
        $this->assertTrue($maps['invert_dark']);
        $this->assertFalse($maps['geocoding']);
        $this->assertSame(256, $maps['tile_size']);
        $this->assertSame(0, $maps['zoom_offset']);
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
    }

    public function test_the_config_endpoint_carries_the_maps_block(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.maps.provider', 'mapbox')
            ->assertJsonStructure(['data' => ['maps' => ['tile_url_light', 'tile_url_dark', 'tile_size', 'zoom_offset', 'attribution', 'default_center']]]);
    }

    // ------------------------------------------------------------- geocoding

    public function test_reverse_geocoding_answers_nulls_rather_than_failing_without_a_provider(): void
    {
        $this->app->instance(Geocoder::class, new NullGeocoder());

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
        $this->app->instance(Geocoder::class, new MapboxGeocoder());

        $this->getJson('/api/v1/geo/reverse?lat=31.4472&lng=31.5142')
            ->assertOk()
            ->assertJsonPath('data.address', 'شارع البحر، جمصة، الدقهلية')
            ->assertJsonPath('data.neighborhood', 'الكورنيش')
            ->assertJsonPath('data.city', 'جمصة');
    }

    public function test_a_second_lookup_of_the_same_point_costs_no_request(): void
    {
        config(['maps.mapbox.token' => 'pk.test-token']);
        Cache::flush();
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => [['place_name' => 'جمصة']]])]);
        $geocoder = new MapboxGeocoder();

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
        $this->app->instance(Geocoder::class, new MapboxGeocoder());

        $this->getJson('/api/v1/geo/reverse?lat=31.4472&lng=31.5142')
            ->assertOk()
            ->assertJsonPath('data.address', null);
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
        $this->app->instance(Geocoder::class, new MapboxGeocoder());

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

        $this->assertSame('1234', \App\Models\Order::query()->firstOrFail()->getRawOriginal('pickup_otp'));
    }
}
