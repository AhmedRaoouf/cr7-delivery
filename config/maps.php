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
 * With no credential of any kind the whole thing degrades to OpenStreetMap's
 * public raster tiles and geocoding simply answers `null`. The app keeps
 * working. `MapStyles::payload()` owns the order of preference.
 */
return [

    // Which provider to prefer. One whose credential is missing is skipped, not
    // served broken — see `MapStyles::payload()`. `osm` always works.
    //
    // `openfreemap` is the default since D-126: it is the only one of the four
    // that can print Arabic labels, because it is the only one that is not
    // raster. The raster block is published beside it exactly as before and is
    // the fallback for any client that cannot draw vector tiles — a 1.0.5 app,
    // for one — so naming it here changes the `provider` string and nothing
    // else about what a raster client is handed.
    'provider' => env('MAPS_PROVIDER', 'openfreemap'),

    /**
     * D-126 — the vector basemap, drawn in Arabic.
     *
     * Measured in Brave over جمصة on 2026-09-16: Mapbox **raster** tiles ignore
     * `?language=ar` — a byte-different tile with identical English labels —
     * which is D-123 and is not fixable at this end. A raster tile is a picture
     * of somebody else's labels.
     *
     * OpenFreeMap serves OpenMapTiles vector tiles whose features carry
     * `name:ar`: tile `14/9627/6683` over this town holds ‪74‬ Arabic strings,
     * «جمصة», «مصيف جمصة», «شارع الحرية», «صيدليه الدكتوره شيماء العياضي». MIT,
     * **no API key, no request cap, commercial use allowed**, attribution
     * required. There is no SLA, which is exactly why the raster block above it
     * stays published.
     *
     * Two things are the client's and are published so it cannot get them wrong:
     * the Arabic label expression is applied on the client, per `symbol` layer
     * (`['coalesce', ['get','name:ar'], ['get','name:latin'], ['get','name']]`),
     * and **the RTL text plugin must be loaded first** — without it MapLibre
     * renders «جمصة» as «ةمصج», which is the one failure that looks like a font
     * problem and is not.
     */
    'openfreemap' => [
        'host' => rtrim((string) env('OPENFREEMAP_HOST', 'https://tiles.openfreemap.org'), '/'),
        'style_light' => env('OPENFREEMAP_STYLE_LIGHT', 'liberty'),
        'style_dark' => env('OPENFREEMAP_STYLE_DARK', 'dark'),
        'max_zoom' => (int) env('OPENFREEMAP_MAX_ZOOM', 20),
        // Required by the licence of the data underneath, not a nicety.
        'attribution' => 'OpenFreeMap © OpenMapTiles Data from OpenStreetMap',
    ],

    'vector' => [
        // False means every client draws the raster block and nothing else — the
        // switch back, with no release, if OpenFreeMap is ever unavailable.
        'enabled' => (bool) env('MAPS_VECTOR', true),
        'rtl_plugin_url' => env(
            'MAPS_RTL_PLUGIN_URL',
            'https://cdn.jsdelivr.net/npm/@mapbox/mapbox-gl-rtl-text@0.3.0/dist/mapbox-gl-rtl-text.js',
        ),
    ],

    /**
     * D-127 — forward search.
     *
     * `local_is_enough` is how many rows the free sources — this platform's own
     * shops and the launch city's address book — must produce before the billed
     * providers are skipped for that query. It is the balance the operator asked
     * for: «خلي ال requests بتاعه الخريطه مقتصده», without going back to the
     * rule that a single weak local substring hit suppressed Mapbox and
     * Nominatim entirely.
     */
    'search' => [
        'local_is_enough' => (int) env('MAPS_SEARCH_LOCAL_IS_ENOUGH', 5),
    ],

    /**
     * CARTO's raster basemaps — the densest labels of the three, and the only
     * free one with a real dark style, so the client stops inverting light
     * tiles in dark mode.
     *
     * **It needs a key.** `basemaps.cartocdn.com` still answers 200 without
     * one, and the tile it answers with has «API KEY REQUIRED» stamped across
     * it. A free key is a sign-up at carto.com/basemaps/apikey; without one
     * `MapStyles` will not select this provider at all.
     *
     * Attribution is a licence requirement, not a nicety: `MapStyles` publishes
     * «© OpenStreetMap © CARTO» and every map draws it.
     */
    'carto' => [
        'api_key' => env('CARTO_API_KEY'),
        'host' => env('CARTO_HOST', 'basemaps.cartocdn.com'),
        // a…d are CDN shards of the same store. Left as `{s}` in the template
        // with this list beside it, so the client spreads a screenful of tiles
        // over four connection pools instead of one.
        'subdomains' => ['a', 'b', 'c', 'd'],
        'style_light' => env('CARTO_STYLE_LIGHT', 'voyager'),
        'style_dark' => env('CARTO_STYLE_DARK', 'dark_all'),
        'tile_size' => 256,
        'zoom_offset' => 0,
        'max_zoom' => 20,
        // Emits `{r}`, which the client fills with `@2x` on a high-density
        // screen and with nothing on any other.
        'retina' => true,
    ],

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

        /**
         * The same two, drawn in Arabic — published instead of the pair above when the
         * request's locale is Arabic (`Accept-Language`, which is Arabic unless it says
         * `en`). Empty means «no Arabic style», and the pair above is published for
         * everybody, exactly as before.
         *
         * They are separate styles because they have to be. The raster Static Tiles API
         * takes no `language` parameter: a style's labels are whatever the style was
         * built to print, and `streets-v12` / `dark-v11` print English. An Arabic basemap
         * is a copy of each made in Mapbox Studio with the label layers set to `name_ar`
         * (falling back to `name`), published under this account — `owner/style-id`, the
         * same form as the defaults. Each light/dark half falls back on its own, so an
         * operator can publish the light one first.
         */
        'style_light_ar' => env('MAPBOX_STYLE_LIGHT_AR', ''),
        'style_dark_ar' => env('MAPBOX_STYLE_DARK_AR', ''),

        // 512 px tiles cover four times the area of 256 px ones for the same
        // single billed request, so the free 200k/month goes four times as far.
        // `zoom_offset` is what keeps the zoom levels lining up at that size.
        'tile_size' => 512,
        'zoom_offset' => -1,
        'retina' => true,
        // 18 is where the map stopped resolving street detail; `streets-v12`
        // renders well past 20.
        'max_zoom' => 20,
    ],

    // The floor: no account, no key, no quota to sign for. One light set and
    // no retina set, so the client inverts it in dark mode and asks for no
    // `@2x`. Zoom 20 answers 400 here, which is why the ceiling is 19.
    'osm' => [
        'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'tile_size' => 256,
        'zoom_offset' => 0,
        'max_zoom' => 19,

        /**
         * D-84 — geocoding with no account anywhere.
         *
         * Operator, 2026-09-11: «شوف خريطه عليها كل المناطق وتكون مجانيه يعني
         * يبقا فيها كل حاجه google maps». Google's geocoder cannot be called
         * without a billing account; Nominatim can be called without anything,
         * and it reads the same OpenStreetMap data CARTO's tiles are drawn
         * from — so the names on the map and the names from the lookup agree.
         *
         * Its usage policy asks for two things and both are honoured here: an
         * identifying `User-Agent` (below), and no bulk traffic — every answer
         * is cached server-side for `cache.reverse_days`, so a dragged pin
         * spends one request per ‪11‬ m of *new* ground and none at all on
         * ground any customer has already covered.
         */
        'geocoding' => (bool) env('OSM_GEOCODING', true),
        'nominatim' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'CR7Delivery/1.0 (+https://cr7-delivery.com)'),
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
        // Our own shops, cached far more briefly than a street name: a place can
        // be renamed, moved or deactivated from the dashboard this afternoon,
        // and a geocoding answer that outlives that sends a captain to an empty
        // unit.
        'places_minutes' => (int) env('MAPS_CACHE_PLACES_MINUTES', 30),
        // A route is cached far more briefly than a street name: roads do not
        // move, but traffic does, and Mapbox's duration is the useful half of
        // the answer. Ten minutes is long enough that a captain riding one
        // street reuses a single answer and short enough that the ETA is real.
        'route_minutes' => (int) env('MAPS_CACHE_ROUTE_MINUTES', 10),
    ],

    'timeout_seconds' => (int) env('MAPS_TIMEOUT_SECONDS', 6),
];
