<?php

namespace App\Support;

/**
 * The `maps` block of `GET /config` — one place that decides what every client
 * draws under its pins.
 *
 * The tile template goes over the wire with `{z}/{x}/{y}` left in it: those are
 * the client's to fill per frame. `tile_size` and `zoom_offset` ride along
 * because a 512 px tile set is a different zoom scale from a 256 px one, and a
 * client that guesses draws the whole town one level out.
 */
final class MapStyles
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        $token = config('maps.mapbox.token');

        if (blank($token) || config('maps.provider') !== 'mapbox') {
            return self::osm();
        }

        return [
            'provider' => 'mapbox',
            'tile_url_light' => self::mapboxTiles(config('maps.mapbox.style_light'), $token),
            'tile_url_dark' => self::mapboxTiles(config('maps.mapbox.style_dark'), $token),
            'tile_size' => (int) config('maps.mapbox.tile_size', 512),
            'zoom_offset' => (int) config('maps.mapbox.zoom_offset', -1),
            'max_zoom' => 18,
            // Required by the Mapbox terms of service. Kept as text rather than
            // the wordmark so RTL layouts do not have to host a bitmap.
            'attribution' => '© Mapbox © OpenStreetMap',
            'attribution_url' => 'https://www.mapbox.com/about/maps/',
            // Dark is a real Mapbox style, so the client must stop inverting the
            // light one — an inverted dark style comes back out white.
            'invert_dark' => false,
            'geocoding' => true,
            'default_center' => config('maps.default_center'),
        ];
    }

    /** @return array<string, mixed> */
    private static function osm(): array
    {
        $url = (string) config('maps.osm.tile_url');

        return [
            'provider' => 'osm',
            'tile_url_light' => $url,
            // OSM ships one light set. The client inverts it on luminance,
            // which is why `invert_dark` is on here and off for Mapbox.
            'tile_url_dark' => $url,
            'tile_size' => (int) config('maps.osm.tile_size', 256),
            'zoom_offset' => (int) config('maps.osm.zoom_offset', 0),
            'max_zoom' => 18,
            'attribution' => '© OpenStreetMap',
            'attribution_url' => 'https://www.openstreetmap.org/copyright',
            'invert_dark' => true,
            'geocoding' => false,
            'default_center' => config('maps.default_center'),
        ];
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
