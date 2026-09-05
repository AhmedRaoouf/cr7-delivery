<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Maps\MapboxRouter;
use App\Services\Maps\NullRouter;
use App\Services\Maps\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «عايزها مظبوطة يعني عايز الشارع مظبوط» — the tracking line follows the road now.
 *
 * The pieces that can silently be wrong are the axis order and the fallback. Mapbox
 * answers `[lng, lat]`; every map in this application reads `[lat, lng]`. Swapping
 * 31.47, 31.38 gives a point that is still in Egypt and still plausible, so the defect
 * would not look like one — it would look like the captain riding a parallel street.
 */
class DirectionsTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = [31.4369403, 31.5267197];

    private const TO = [31.3892245, 31.4720504];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('maps.mapbox.token', 'pk.test');
        Cache::flush();
    }

    private function fakeRoute(): void
    {
        Http::fake([
            'api.mapbox.com/directions/*' => Http::response([
                'routes' => [[
                    'distance' => 8420.4,
                    'duration' => 1080.0,
                    'geometry' => ['coordinates' => [
                        [31.5267197, 31.4369403],
                        [31.5100000, 31.4200000],
                        [31.4720504, 31.3892245],
                    ]],
                ]],
            ]),
        ]);
    }

    public function test_the_road_comes_back_as_lat_lng_in_driving_order(): void
    {
        $this->fakeRoute();

        $route = app(MapboxRouter::class)->route(...[...self::FROM, ...self::TO]);

        $this->assertNotNull($route);
        $this->assertSame([31.4369403, 31.5267197], $route['geometry'][0]);
        $this->assertSame([31.3892245, 31.4720504], $route['geometry'][2]);
        $this->assertSame(8.42, $route['distance_km']);
        $this->assertSame(18.0, $route['duration_minutes']);
    }

    public function test_a_second_request_from_the_same_street_is_not_a_second_billed_one(): void
    {
        $this->fakeRoute();
        $router = app(MapboxRouter::class);

        $router->route(...[...self::FROM, ...self::TO]);
        // 30 cm further along. A captain pinging every few seconds must not buy a
        // route every ping.
        $router->route(self::FROM[0] + 0.000003, self::FROM[1], ...self::TO);

        Http::assertSentCount(1);
    }

    public function test_two_cities_are_not_a_delivery_and_are_never_requested(): void
    {
        $this->fakeRoute();

        // جمصة to مدينة 15 مايو — 200 km, and no captain rides it.
        $route = app(MapboxRouter::class)->route(31.4369403, 31.5267197, 29.826534, 31.364231);

        $this->assertNull($route);
        Http::assertNothingSent();
    }

    public function test_a_provider_outage_is_a_straight_line_and_not_an_error(): void
    {
        Http::fake(['api.mapbox.com/directions/*' => Http::response('', 502)]);

        $this->assertNull(app(MapboxRouter::class)->route(...[...self::FROM, ...self::TO]));
    }

    public function test_no_token_means_no_router_and_no_request(): void
    {
        config()->set('maps.mapbox.token', null);

        $this->assertInstanceOf(NullRouter::class, app(Router::class));
    }

    public function test_the_endpoint_answers_a_null_route_rather_than_failing(): void
    {
        Http::fake(['api.mapbox.com/directions/*' => Http::response('', 500)]);

        Sanctum::actingAs(User::factory()->create(['type' => 'customer']));

        $this->withHeader('X-App', 'app')
            ->getJson('/api/v1/geo/route?from_lat=31.43&from_lng=31.52&to_lat=31.38&to_lng=31.47')
            ->assertOk()
            ->assertJsonPath('data.route', null);
    }

    public function test_the_endpoint_is_not_public(): void
    {
        $this->withHeader('X-App', 'app')
            ->getJson('/api/v1/geo/route?from_lat=31.43&from_lng=31.52&to_lat=31.38&to_lng=31.47')
            ->assertUnauthorized();
    }
}
