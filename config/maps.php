<?php

/**
 * Map tiles and geocoding.
 *
 * The device fetches tiles straight from the provider — proxying raster tiles
 * through PHP would put every pan and zoom through Laravel — so the *public*
 * token has to reach the app. It is published through `GET /config` rather than
 * compiled into the binary, which is what makes rotating it a server-side edit
 * instead of a store release.
 *
 * Geocoding is the opposite: low volume, cacheable, and worth keeping the token
 * off the wire, so it is proxied through `/geo/*` and cached here.
 *
 * With no token set the whole thing degrades to OpenStreetMap's public raster
 * tiles and geocoding simply answers `null`. The app keeps working.
 */
return [

    'provider' => env('MAPS_PROVIDER', 'mapbox'),

    'mapbox' => [
        // A **public** token (`pk.…`). Never a secret (`sk.…`) token: this one
        // is published to every client by design.
        //
        // Two names because two commits arrived with one each. `.env.example`
        // documented `MAPBOX_ACCESS_TOKEN` while this — the only thing that
        // actually reads a token — wanted `MAPBOX_TOKEN`, so setting the
        // documented one left the app on OpenStreetMap with no error anywhere
        // to explain why. Either name works; `MAPBOX_TOKEN` is the one to use.
        'token' => env('MAPBOX_TOKEN') ?: env('MAPBOX_ACCESS_TOKEN'),

        'style_light' => env('MAPBOX_STYLE_LIGHT', 'mapbox/streets-v12'),
        'style_dark' => env('MAPBOX_STYLE_DARK', 'mapbox/dark-v11'),

        // 512 px tiles cover four times the area of 256 px ones for the same
        // single billed request, so the free 200k/month goes four times as far.
        // `zoom_offset` is what keeps the zoom levels lining up at that size.
        'tile_size' => 512,
        'zoom_offset' => -1,
        'retina' => true,
    ],

    // The fallback, and what the app draws until a token exists.
    'osm' => [
        'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'tile_size' => 256,
        'zoom_offset' => 0,
    ],

    /**
     * Where the map opens, and what forward search is biased towards — جمصة,
     * doc 01's launch city.
     */
    'default_center' => [
        'lat' => (float) env('MAPS_DEFAULT_LAT', 31.4472),
        'lng' => (float) env('MAPS_DEFAULT_LNG', 31.5142),
    ],

    'country' => env('MAPS_COUNTRY', 'eg'),

    'cache' => [
        // A reverse lookup is keyed on coordinates rounded to 4 decimals (~11 m).
        // Streets do not move, so this is cached hard: it is what keeps a
        // dragged pin from spending a geocoding request every 11 metres.
        'reverse_days' => (int) env('MAPS_CACHE_REVERSE_DAYS', 30),
        'search_days' => (int) env('MAPS_CACHE_SEARCH_DAYS', 7),
        // A route is cached far more briefly than a street name: roads do not
        // move, but traffic does, and Mapbox's duration is the useful half of
        // the answer. Ten minutes is long enough that a captain riding one
        // street reuses a single answer and short enough that the ETA is real.
        'route_minutes' => (int) env('MAPS_CACHE_ROUTE_MINUTES', 10),
    ],

    'timeout_seconds' => (int) env('MAPS_TIMEOUT_SECONDS', 6),
];
