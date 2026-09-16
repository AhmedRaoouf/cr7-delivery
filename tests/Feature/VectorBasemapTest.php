<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-126 — the basemap is OpenFreeMap vector, drawn in Arabic.
 *
 * Mapbox raster tiles ignore `?language=ar` — measured 2026-09-16, a byte-different
 * tile with identical English labels — so no raster basemap can be localised from
 * this end (D-123). OpenFreeMap's vector tiles carry `name:ar`, and the client
 * rewrites each label layer to prefer it.
 *
 * The contract is **additive**: a 1.0.5 app and the deployed dashboards read the
 * raster keys and must go on reading exactly what they read yesterday. So the
 * raster block is pinned here literally, as it was before this change, rather than
 * compared against whatever the code happens to produce today.
 */
class VectorBasemapTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRE = ['lat' => 31.4472, 'lng' => 31.5142];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maps.default_center' => self::CENTRE,
            'maps.mapbox.style_light' => 'mapbox/streets-v12',
            'maps.mapbox.style_dark' => 'mapbox/dark-v11',
            'maps.mapbox.style_light_ar' => '',
            'maps.mapbox.style_dark_ar' => '',
            'maps.carto.api_key' => null,
            'maps.osm.geocoding' => true,
            'maps.osm.nominatim' => 'https://nominatim.openstreetmap.org',
            'maps.vector.enabled' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function maps(string $language = 'ar'): array
    {
        return $this->withHeaders(['Accept-Language' => $language])
            ->getJson('/api/v1/config')
            ->assertOk()
            ->json('data.maps');
    }

    /** @return array<string, mixed> the osm block exactly as `MapStyles` published it before D-126 */
    private static function osmBefore(): array
    {
        return [
            'provider' => 'osm',
            'tile_url_light' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'tile_url_dark' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'subdomains' => [],
            'tile_size' => 256,
            'zoom_offset' => 0,
            'max_zoom' => 19,
            'attribution' => '© OpenStreetMap',
            'attribution_url' => 'https://www.openstreetmap.org/copyright',
            'invert_dark' => true,
            'geocoding' => true,
            'default_center' => self::CENTRE,
            'language' => 'ar',
        ];
    }

    /** @return array<string, mixed> the mapbox block exactly as `MapStyles` published it before D-126 */
    private static function mapboxBefore(): array
    {
        return [
            'provider' => 'mapbox',
            'tile_url_light' => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/512/{z}/{x}/{y}@2x?access_token=pk.test-token',
            'tile_url_dark' => 'https://api.mapbox.com/styles/v1/mapbox/dark-v11/tiles/512/{z}/{x}/{y}@2x?access_token=pk.test-token',
            'subdomains' => [],
            'tile_size' => 512,
            'zoom_offset' => -1,
            'max_zoom' => 20,
            'attribution' => '© Mapbox © OpenStreetMap',
            'attribution_url' => 'https://www.mapbox.com/about/maps/',
            'invert_dark' => false,
            'geocoding' => true,
            'default_center' => self::CENTRE,
            'language' => 'ar',
        ];
    }

    public function test_an_arabic_request_gets_the_vector_block_labelled_in_arabic(): void
    {
        config(['maps.provider' => 'openfreemap', 'maps.mapbox.token' => null]);

        $vector = $this->maps('ar')['vector'];

        $this->assertSame([
            'enabled' => true,
            'provider' => 'openfreemap',
            'style_url_light' => 'https://tiles.openfreemap.org/styles/liberty',
            'style_url_dark' => 'https://tiles.openfreemap.org/styles/dark',
            'label_language' => 'ar',
            'rtl_plugin_url' => 'https://cdn.jsdelivr.net/npm/@mapbox/mapbox-gl-rtl-text@0.3.0/dist/mapbox-gl-rtl-text.js',
            'max_zoom' => 20,
            'attribution' => 'OpenFreeMap © OpenMapTiles Data from OpenStreetMap',
        ], $vector);
    }

    public function test_the_label_language_follows_accept_language_like_the_raster_one(): void
    {
        config(['maps.provider' => 'openfreemap']);

        $english = $this->maps('en-US');

        $this->assertSame('en', $english['language']);
        $this->assertSame('en', $english['vector']['label_language']);
    }

    public function test_the_raster_keys_are_byte_identical_to_before_on_osm(): void
    {
        config(['maps.provider' => 'osm', 'maps.mapbox.token' => null]);

        $maps = $this->maps('ar');
        unset($maps['vector']);

        $this->assertSame(self::osmBefore(), $maps);
    }

    public function test_the_raster_keys_are_byte_identical_to_before_on_mapbox(): void
    {
        config(['maps.provider' => 'mapbox', 'maps.mapbox.token' => 'pk.test-token']);

        $maps = $this->maps('ar');
        unset($maps['vector']);

        $this->assertSame(self::mapboxBefore(), $maps);
    }

    public function test_openfreemap_names_itself_and_leaves_every_raster_key_as_the_fallback(): void
    {
        config(['maps.provider' => 'openfreemap', 'maps.mapbox.token' => 'pk.test-token']);

        $maps = $this->maps('ar');

        $this->assertSame('openfreemap', $maps['provider']);
        $this->assertTrue($maps['vector']['enabled']);

        unset($maps['vector']);
        $this->assertSame(['provider' => 'openfreemap'] + self::mapboxBefore(), $maps);
    }

    public function test_openfreemap_with_no_key_anywhere_falls_back_to_the_osm_raster(): void
    {
        config(['maps.provider' => 'openfreemap', 'maps.mapbox.token' => null]);

        $maps = $this->maps('ar');
        unset($maps['vector']);

        $this->assertSame(['provider' => 'openfreemap'] + self::osmBefore(), $maps);
    }

    public function test_switching_vector_off_is_published_rather_than_hidden(): void
    {
        // «`vector.enabled` false ⇒ everyone draws raster» — a client must be able to
        // tell «off» from «this server predates vector», and only one of them is here.
        config(['maps.provider' => 'openfreemap', 'maps.vector.enabled' => false]);

        $this->assertFalse($this->maps('ar')['vector']['enabled']);
    }

    public function test_the_styles_follow_configuration(): void
    {
        config([
            'maps.provider' => 'openfreemap',
            'maps.openfreemap.style_light' => 'positron',
            'maps.openfreemap.style_dark' => 'fiord',
        ]);

        $vector = $this->maps('ar')['vector'];

        $this->assertSame('https://tiles.openfreemap.org/styles/positron', $vector['style_url_light']);
        $this->assertSame('https://tiles.openfreemap.org/styles/fiord', $vector['style_url_dark']);
    }

    public function test_the_mapbox_arabic_style_plumbing_still_answers(): void
    {
        // It cannot localise a raster tile (D-123), and it is kept anyway: a Mapbox
        // Studio style with its label layers set to `name_ar` is the one raster route
        // to Arabic, and deleting the plumbing would delete the record of that.
        config([
            'maps.provider' => 'mapbox',
            'maps.mapbox.token' => 'pk.test-token',
            'maps.mapbox.style_light_ar' => 'cr7delivery/arabic-light',
        ]);

        $this->assertStringContainsString('/cr7delivery/arabic-light/', $this->maps('ar')['tile_url_light']);
    }
}
