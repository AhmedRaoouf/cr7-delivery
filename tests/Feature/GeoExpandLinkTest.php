<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GET /geo/expand-link` — the one map tool that has to leave the server.
 *
 * Operator, 2026-09-08: «اجيب لينك من google maps». The phone's share sheet produces
 * `maps.app.goo.gl/xxxx`, which carries no coordinates; they exist only in the URL it
 * redirects to, and a browser cannot read a cross-origin redirect. So this endpoint
 * follows it — which makes it the only route in the app that fetches a URL a user chose,
 * and the host allowlist is the whole of its security.
 */
class GeoExpandLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_follows_a_short_link_to_the_point_behind_it(): void
    {
        Http::fake([
            '*' => Http::response('<html></html>', 200, [
                'X-Guzzle-Redirect-History' => [
                    'https://www.google.com/maps/place/CR7/@31.4400,31.5200,17z/data=!3d31.4456!4d31.5289',
                ],
            ]),
        ]);

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://maps.app.goo.gl/AbCdEfGh'))
            ->assertOk()
            ->assertJsonPath('data.lat', 31.4456)
            ->assertJsonPath('data.lng', 31.5289);
    }

    public function test_a_host_we_do_not_know_is_never_fetched(): void
    {
        // Without this check an authenticated user could aim a server-side GET at
        // anything the server can reach — the metadata service, or localhost.
        Http::fake();

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('http://169.254.169.254/latest/meta-data/'))
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_map_link');

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://google.com.attacker.example/x'))
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_map_link');

        Http::assertNothingSent();
    }

    public function test_a_link_that_names_no_place_says_so_rather_than_guessing(): void
    {
        Http::fake([
            '*' => Http::response('<html>nothing here</html>', 200),
        ]);

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://maps.app.goo.gl/Nothing'))
            ->assertStatus(422)
            ->assertJsonPath('error', 'map_link_has_no_point');
    }

    public function test_the_page_answers_when_the_url_does_not(): void
    {
        // Google sometimes redirects to a consent page whose URL carries no point, but
        // the body it serves still holds the pin.
        Http::fake([
            '*' => Http::response(
                '<html><meta content="https://maps.google.com/maps?ll=31.4456,31.5289"></html>',
                200,
            ),
        ]);

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://maps.app.goo.gl/Consent'))
            ->assertOk()
            ->assertJsonPath('data.lat', 31.4456);
    }
}
