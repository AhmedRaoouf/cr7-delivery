<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * doc 64 — the map in Arabic.
 *
 * Production draws Mapbox `streets-v12` / `dark-v11` as raster tiles, which print English
 * labels, and the raster Static Tiles API takes no language parameter. The Arabic basemap
 * is therefore a style of its own, published through `GET /config` to an Arabic request
 * when one is configured, and never to anybody when it is not.
 */
class ArabicBasemapTest extends TestCase
{
    use RefreshDatabase;

    private function maps(string $language): array
    {
        return $this->withHeaders(['Accept-Language' => $language])
            ->getJson('/api/v1/config')
            ->assertOk()
            ->json('data.maps');
    }

    public function test_an_arabic_request_gets_the_arabic_style_and_an_english_one_does_not(): void
    {
        config([
            'maps.provider' => 'mapbox',
            'maps.mapbox.token' => 'pk.test-token',
            'maps.mapbox.style_light_ar' => 'cr7delivery/arabic-light',
            'maps.mapbox.style_dark_ar' => 'cr7delivery/arabic-dark',
        ]);

        $arabic = $this->maps('ar');
        $this->assertSame('ar', $arabic['language']);
        $this->assertStringContainsString('/styles/v1/cr7delivery/arabic-light/tiles/512/{z}/{x}/{y}@2x?access_token=pk.test-token', $arabic['tile_url_light']);
        $this->assertStringContainsString('/styles/v1/cr7delivery/arabic-dark/tiles/', $arabic['tile_url_dark']);

        $english = $this->maps('en-US');
        $this->assertSame('en', $english['language']);
        $this->assertStringContainsString('/styles/v1/mapbox/streets-v12/tiles/', $english['tile_url_light']);
        $this->assertStringContainsString('/styles/v1/mapbox/dark-v11/tiles/', $english['tile_url_dark']);
    }

    public function test_with_no_arabic_style_everybody_gets_todays_map(): void
    {
        config(['maps.provider' => 'mapbox', 'maps.mapbox.token' => 'pk.test-token']);

        $arabic = $this->maps('ar');

        $this->assertSame('ar', $arabic['language']);
        $this->assertStringContainsString('/styles/v1/mapbox/streets-v12/tiles/', $arabic['tile_url_light']);
        $this->assertStringContainsString('/styles/v1/mapbox/dark-v11/tiles/', $arabic['tile_url_dark']);
    }

    public function test_each_half_falls_back_on_its_own(): void
    {
        config([
            'maps.provider' => 'mapbox',
            'maps.mapbox.token' => 'pk.test-token',
            'maps.mapbox.style_light_ar' => 'cr7delivery/arabic-light',
            'maps.mapbox.style_dark_ar' => '',
        ]);

        $arabic = $this->maps('ar');

        $this->assertStringContainsString('/cr7delivery/arabic-light/', $arabic['tile_url_light']);
        $this->assertStringContainsString('/mapbox/dark-v11/', $arabic['tile_url_dark']);
    }

    public function test_the_language_is_published_whatever_the_provider(): void
    {
        config(['maps.provider' => 'osm', 'maps.mapbox.style_light_ar' => 'cr7delivery/arabic-light']);

        $maps = $this->maps('ar');

        // OpenStreetMap has one style and it is not ours to swap.
        $this->assertSame('osm', $maps['provider']);
        $this->assertSame('ar', $maps['language']);
        $this->assertStringContainsString('tile.openstreetmap.org', $maps['tile_url_light']);
    }

    /**
     * `/config` is not cached on the server, so there is no key there to get wrong; what
     * can cache it is everything between the server and the screen. It says what it varies on.
     */
    public function test_the_answer_says_it_varies_with_the_language(): void
    {
        $vary = implode(', ', $this->getJson('/api/v1/config')->assertOk()->headers->all('vary'));

        $this->assertStringContainsString('Accept-Language', $vary);
        $this->assertStringContainsString('Authorization', $vary);
    }
}
