<?php

namespace Tests\Feature;

use App\Services\Maps\Geocoder;
use App\Services\Maps\NullGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-127 — one throttle bucket per geocoding route, and a ‪429‬ that says what it is.
 *
 * `/geo/reverse`, `/geo/search` and `/geo/expand-link` shared a single
 * `throttle:60,1` keyed by IP. The address picker's ‪700‬ ms reverse debounce can
 * spend ~‪85‬ of those in a minute on its own, and the search box then answered ‪429‬,
 * which the client showed as «لا توجد نتائج».
 */
class GeoThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No network and no gazetteer: these tests are about the bucket, not the answer.
        $this->app->instance(Geocoder::class, new NullGeocoder);
    }

    public function test_a_dragged_pin_does_not_spend_the_search_allowance(): void
    {
        // Seventy is past the old shared sixty.
        for ($i = 0; $i < 70; $i++) {
            $this->getJson('/api/v1/geo/reverse?lat=31.4453&lng=31.5344')->assertOk();
        }

        $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))->assertOk();
    }

    public function test_a_throttled_search_answers_429_in_the_envelope_with_its_own_code(): void
    {
        for ($i = 0; $i < 90; $i++) {
            $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))->assertOk();
        }

        $refused = $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'geo_search_rate_limited')
            ->assertJsonPath('message', 'البحث سريع جدًا، انتظر لحظة');

        $this->assertIsInt($refused->json('meta.retry_after'));
        $this->assertNotEmpty($refused->headers->get('Retry-After'));

        // The other routes keep their own allowance.
        $this->getJson('/api/v1/geo/reverse?lat=31.4453&lng=31.5344')->assertOk();
    }

    public function test_two_customers_behind_one_carrier_address_do_not_share_a_bucket(): void
    {
        for ($i = 0; $i < 90; $i++) {
            $this->withToken('first-customer')->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))->assertOk();
        }

        $this->withToken('first-customer')->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))->assertStatus(429);
        $this->withToken('second-customer')->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))->assertOk();
    }

    public function test_expanding_links_has_its_own_tighter_bucket_and_code(): void
    {
        // An unsupported host is refused before any fetch, so no network is needed.
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://example.com/x'))->assertStatus(422);
        }

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://example.com/x'))
            ->assertStatus(429)
            ->assertJsonPath('error', 'geo_expand_rate_limited');

        $this->getJson('/api/v1/geo/search?q='.urlencode('الكورنيش'))->assertOk();
    }
}
