<?php

namespace App\Support;

use App\Providers\AppServiceProvider;

/**
 * The `maps` block of `GET /config` — one place that decides what every client
 * draws under its pins.
 *
 * The tile template goes over the wire with its placeholders left in it: those
 * are the client's to fill per frame.
 *
 * | placeholder | filled with |
 * |---|---|
 * | `{z}` `{x}` `{y}` | the tile the viewport is asking for |
 * | `{s}` | one of `subdomains`, or nothing when that list is empty |
 * | `{r}` | `@2x` on a high-density screen, nothing otherwise |
 *
 * `tile_size` and `zoom_offset` ride along because a 512 px tile set is a
 * different zoom scale from a 256 px one, and a client that guesses draws the
 * whole town one level out. `max_zoom` rides along because it is the level the
 * map stops resolving street detail at, and every provider has a different one.
 */
final class MapStyles
{
    /**
     * Whichever provider can actually be served, in preference order.
     *
     * `MAPS_PROVIDER` names the wanted one; a provider whose credential is
     * missing is skipped rather than served broken. That distinction is not
     * theoretical: CARTO's `basemaps.cartocdn.com` answers **200 with a
     * watermarked tile** — «API KEY REQUIRED» stamped diagonally across the
     * map — when no key is sent, so a naive status check calls it healthy and
     * every screen in the app then carries an advert. Verified 2026-09-08 on
     * `voyager`, `dark_all`, `light_all`, the `{s}.basemaps` and the legacy
     * `cartodb-basemaps-*.global.ssl.fastly.net` hosts, at 1x and @2x.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $provider = (string) config('maps.provider', 'osm');
        $mapboxToken = config('maps.mapbox.token');
        $cartoKey = config('maps.carto.api_key');

        return match (true) {
            $provider === 'osm' => self::osm(),
            $provider === 'carto' && filled($cartoKey) => self::carto((string) $cartoKey),
            $provider === 'mapbox' && filled($mapboxToken) => self::mapbox((string) $mapboxToken),
            // The named provider cannot answer. Prefer whichever key does exist
            // over degrading straight to the plainest map we have.
            filled($mapboxToken) => self::mapbox((string) $mapboxToken),
            filled($cartoKey) => self::carto((string) $cartoKey),
            default => self::osm(),
        };
    }

    /**
     * CARTO's raster basemaps — the densest labels of the three, and the only
     * free one with a **real** dark style.
     *
     * Both halves matter. Operator, 2026-09-07: «الخريطة اللي إنت عاملها دي، مش
     * ظاهر فيها غير الأماكن المشهورة بس». `voyager` prints districts, streets
     * and POIs where the plain OpenStreetMap render prints a handful, and it
     * serves to zoom 20 rather than stopping at 19. `dark_all` is drawn dark,
     * so `invert_dark` is false and the client stops running a luminance
     * inversion over light tiles — an inversion that greys every label it
     * passes through.
     *
     * Needs `CARTO_API_KEY`. See {@see payload()} for what happens without one.
     *
     * @return array<string, mixed>
     */
    private static function carto(string $key): array
    {
        return [
            'provider' => 'carto',
            'tile_url_light' => self::cartoTiles((string) config('maps.carto.style_light', 'voyager'), $key),
            'tile_url_dark' => self::cartoTiles((string) config('maps.carto.style_dark', 'dark_all'), $key),
            // The CDN shards a…d. Published as a list rather than baked into
            // the host because the tile fetches are HTTP/1.1 — four hostnames
            // is four connection pools, and a pinch-out asks for thirty tiles
            // at once.
            'subdomains' => array_values((array) config('maps.carto.subdomains', [])),
            'tile_size' => (int) config('maps.carto.tile_size', 256),
            'zoom_offset' => (int) config('maps.carto.zoom_offset', 0),
            'max_zoom' => (int) config('maps.carto.max_zoom', 20),
            // Required by CARTO's terms, and by ODbL for the data underneath.
            'attribution' => '© OpenStreetMap © CARTO',
            'attribution_url' => 'https://carto.com/attributions',
            'invert_dark' => false,
            'geocoding' => self::hasGeocoder(),
            'default_center' => config('maps.default_center'),
        ];
    }

    /** @return array<string, mixed> */
    private static function mapbox(string $token): array
    {
        return [
            'provider' => 'mapbox',
            'tile_url_light' => self::mapboxTiles((string) config('maps.mapbox.style_light'), $token),
            'tile_url_dark' => self::mapboxTiles((string) config('maps.mapbox.style_dark'), $token),
            'subdomains' => [],
            'tile_size' => (int) config('maps.mapbox.tile_size', 512),
            'zoom_offset' => (int) config('maps.mapbox.zoom_offset', -1),
            // Was 18, which is where the map stopped resolving street detail —
            // half of the 2026-09-07 complaint. `streets-v12` renders past 20.
            'max_zoom' => (int) config('maps.mapbox.max_zoom', 20),
            // Required by the Mapbox terms of service. Kept as text rather than
            // the wordmark so RTL layouts do not have to host a bitmap.
            'attribution' => '© Mapbox © OpenStreetMap',
            'attribution_url' => 'https://www.mapbox.com/about/maps/',
            // Dark is a real Mapbox style, so the client must stop inverting the
            // light one — an inverted dark style comes back out white.
            'invert_dark' => false,
            'geocoding' => self::hasGeocoder(),
            'default_center' => config('maps.default_center'),
        ];
    }

    /**
     * Whether `/geo/reverse` and `/geo/search` will answer with anything.
     *
     * D-84 — this used to be a property of the **tile** provider, which is a
     * different question with a different answer: the OSM basemap reported
     * `geocoding: false`, so the app stopped asking for street names on exactly
     * the deployments that have no Mapbox token and therefore needed Nominatim
     * most. It now reports whichever geocoder {@see AppServiceProvider}
     * would bind.
     */
    public static function hasGeocoder(): bool
    {
        return filled(config('maps.mapbox.token'))
            || ((bool) config('maps.osm.geocoding', true) && filled(config('maps.osm.nominatim')));
    }

    /**
     * The floor: free, key-less, and the only one that needs no account at all.
     *
     * @return array<string, mixed>
     */
    private static function osm(): array
    {
        $url = (string) config('maps.osm.tile_url');

        return [
            'provider' => 'osm',
            'tile_url_light' => $url,
            // OSM ships one light set. The client inverts it on luminance,
            // which is why `invert_dark` is on here and off for the other two.
            'tile_url_dark' => $url,
            'subdomains' => [],
            'tile_size' => (int) config('maps.osm.tile_size', 256),
            'zoom_offset' => (int) config('maps.osm.zoom_offset', 0),
            // 19 is where the standard render actually stops: zoom 20 answers
            // 400, and a client asking for it draws a grey square over the one
            // street the customer was trying to read.
            'max_zoom' => (int) config('maps.osm.max_zoom', 19),
            'attribution' => '© OpenStreetMap',
            'attribution_url' => 'https://www.openstreetmap.org/copyright',
            'invert_dark' => true,
            'geocoding' => self::hasGeocoder(),
            'default_center' => config('maps.default_center'),
        ];
    }

    private static function cartoTiles(string $style, string $key): string
    {
        $host = trim((string) config('maps.carto.host', 'basemaps.cartocdn.com'), '/');
        $shard = ((array) config('maps.carto.subdomains', [])) === [] ? '' : '{s}.';
        $retina = config('maps.carto.retina', true) ? '{r}' : '';

        return sprintf(
            'https://%s%s/rastertiles/%s/{z}/{x}/{y}%s.png?api_key=%s',
            $shard,
            $host,
            trim($style, '/'),
            $retina,
            urlencode($key),
        );
    }

    private static function mapboxTiles(string $style, string $token): string
    {
        $size = (int) config('maps.mapbox.tile_size', 512);
        $retina = config('maps.mapbox.retina', true) ? '@2x' : '';

        // `{z}/{x}/{y}` stay literal: flutter_map substitutes them per tile.
        return sprintf(
            'https://api.mapbox.com/styles/v1/%s/tiles/%d/{z}/{x}/{y}%s?access_token=%s',
            trim($style, '/'),
            $size,
            $retina,
            $token,
        );
    }
}
