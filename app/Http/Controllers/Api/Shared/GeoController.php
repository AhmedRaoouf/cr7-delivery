<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\MapPlaceResource;
use App\Http\Resources\Shared\ServiceZoneResource;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Services\Maps\Geocoder;
use App\Services\Maps\PlaceResults;
use App\Services\Maps\ReportsFailure;
use App\Services\Maps\Router;
use App\Support\ApiResponse;
use App\Support\Geo;
use App\Support\ZoneLocator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * doc 08 §2 — POST /geo/resolve, plus the two geocoding proxies.
 *
 * `resolve` answers a question only this platform can answer: is this point
 * inside a zone we deliver to. `reverse` and `search` answer a question only a
 * map provider can: what is this place called. They are proxied rather than
 * called from the device so the token stays here and the answers are cached
 * once for every customer instead of once per customer.
 */
class GeoController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        // The wide coverage circle the point falls in. It decides **serviceability**
        // and nothing else — it is not a price and it is no longer ever shown.
        $coverage = ZoneLocator::forPoint($lat, $lng);

        // The priced area to put in front of the customer. Null is a legitimate
        // answer and means «pick one», not «we do not deliver here».
        $suggested = ZoneLocator::suggestFor($lat, $lng);

        $city = $suggested?->city ?? $coverage?->city ?? ZoneLocator::cityForPoint($lat, $lng);

        // Every area, one flat list, no city step (operator ruling 2026-09-12).
        // The whole list goes out rather than the resolved city's slice: the
        // customer may be standing in one town and ordering to another, and the
        // old behaviour hid every area they could legitimately want.
        $areas = ZoneLocator::allSelectable();

        return ApiResponse::success([
            'city' => $city,
            // Kept for the older clients that read it; `suggested_zone_id` is the
            // field to use. A suggestion is not a decision — the customer confirms
            // it, because D-70's flyer areas have no shapes and a confident wrong
            // area is worse than an obvious empty one.
            'zone' => $suggested,
            'suggested_zone_id' => $suggested?->id,
            'is_serviceable' => $coverage !== null || $city !== null,
            'requires_zone_choice' => $suggested === null,
            'zones' => ServiceZoneResource::collection($areas),
        ]);
    }

    /**
     * `GET /areas` — every priced area, flat, no city above it.
     *
     * The two-step «city, then area» picker existed because `service_zones`
     * hangs off `cities` and the UI followed the foreign key. The operator ruled
     * it out on 2026-09-12: «هي مناطق على طول». Area names are unique across the
     * whole list and each carries its own fee, so nothing is lost by flattening
     * and one whole screen is saved.
     */
    public function areas(): JsonResponse
    {
        return ApiResponse::success(
            ServiceZoneResource::collection(ZoneLocator::allSelectable()),
        );
    }

    /**
     * The areas a customer may pick in a city, cheapest first — the flyer's own order.
     *
     * The wide legacy circles are excluded by `is_selectable`: they still resolve a pin
     * and still anchor restaurants and captains, but offering one as a price would put a
     * fee on the list that the operator never printed.
     *
     * @return Collection<int, ServiceZone>
     */
    private function pricedZones(int $cityId)
    {
        return ZoneLocator::selectableFor($cityId);
    }

    /**
     * `GET /geo/reverse?lat=&lng=` — the street line under the address pin.
     *
     * Answers `{"address": null}` rather than an error when no provider is
     * configured: the picker's zone verdict does not depend on it, so a missing
     * label must not be a screen the customer has to dismiss.
     */
    public function reverse(Request $request, Geocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $geocoder->reverse(
            (float) $validated['lat'],
            (float) $validated['lng'],
            $this->language($request),
        );

        return ApiResponse::success([
            'address' => $result['address'] ?? null,
            'place' => $result['place'] ?? null,
            // D-84 — the parts, not only the sentence: `CUS-24` prefills
            // «الشارع» from `street`, and it cannot cut that back out of a
            // composed line without guessing where the commas mean something.
            'street' => $result['street'] ?? null,
            'house_number' => $result['house_number'] ?? null,
            'neighborhood' => $result['neighborhood'] ?? null,
            'city' => $result['city'] ?? null,
        ]);
    }

    /**
     * `GET /geo/search?q=&lat=&lng=&limit=&country=` — free-text place lookup,
     * biased to where the customer is standing so «الكورنيش» means this town's.
     *
     * D-127. The row shape gained four fields and lost none:
     *
     * - `kind` — what the row is, so the list can draw a shop, a street, an area
     *   and a door as four different things instead of ten identical pins.
     * - `source` — which of the four sources answered. For us, not the screen.
     * - `distance_m` — from the point the caller sent, and `null` when it sent
     *   none. There is no honest distance from «wherever جمصة is».
     * - `place_id` — on a `kind: place` row only, so tapping a shop this
     *   platform delivers from opens it.
     *
     * And the envelope gained `degraded`. Every geocoder in this application
     * logs a failure and returns `[]`, so until now a refused query, an expired
     * token and a Nominatim ‪403‬ all reached the customer as «لا توجد نتائج» —
     * a sentence they believe, retype around, and give up on. `degraded: true`
     * means every provider that was asked failed and the client should say
     * «تعذّر البحث، حاول مرة أخرى»; `false` with an empty list really does mean
     * nothing matched.
     */
    public function search(Request $request, Geocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'limit' => ['nullable', 'integer', 'between:1,25'],
            // The provider filter, so this is not permanently an Egyptian-only
            // search because one `config()` call said so in 2026. Two letters,
            // ISO-3166-1 alpha-2, and the configured country when absent.
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ]);

        $lat = isset($validated['lat']) ? (float) $validated['lat'] : null;
        $lng = isset($validated['lng']) ? (float) $validated['lng'] : null;
        $limit = (int) ($validated['limit'] ?? 10);

        $rows = $geocoder->search(
            $validated['q'],
            $lat,
            $lng,
            $this->language($request),
            isset($validated['country']) ? strtolower((string) $validated['country']) : null,
            $limit,
        );

        $rows = PlaceResults::withDistance(array_slice($rows, 0, $limit), $lat, $lng);

        // Only a source that reaches a network can be *down*; a source that
        // cannot fail never reports one, so this is false for the local tables
        // and for {@see NullGeocoder} whatever they answered.
        $degraded = $rows === []
            && $geocoder instanceof ReportsFailure
            && $geocoder->lastCallFailed();

        $response = ApiResponse::success($rows, meta: ['count' => count($rows), 'degraded' => $degraded]);

        // On the envelope as well as in `meta`: this is the one field a client
        // has to read before it decides which sentence to show, and it must not
        // depend on knowing that `meta` is where we put things.
        return $response->setData(['degraded' => $degraded] + $response->getData(true));
    }

    /**
     * `GET /geo/expand-link?url=` — the point behind a shortened maps link.
     *
     * Operator, 2026-09-08: «اجيب لينك من google maps». The share sheet on a phone
     * produces `https://maps.app.goo.gl/xxxx`, which carries no coordinates at all —
     * they only appear in the URL it redirects to. Every other shape of link the
     * dashboards parse in the browser; this is the one that needs a round trip, and
     * it has to happen here because the browser cannot read a cross-origin redirect.
     *
     * The host allowlist is the whole security of this endpoint: without it, an
     * authenticated user could aim a server-side GET at any address the server can
     * reach, including the metadata service and anything on localhost.
     *
     * A link that cannot be followed answers 422 with a sentence, never a 500 — the
     * operator's next move is to paste the full link, and they need to be told that.
     */
    public function expandLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url'],
        ]);

        $url = $validated['url'];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || ! $this->isMapHost($host)) {
            return ApiResponse::error('unsupported_map_link', 'هذا الرابط ليس رابط خريطة معروفًا.');
        }

        $cacheKey = 'geo:expand:'.sha1($url);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return ApiResponse::success($cached);
        }

        try {
            $response = Http::withHeaders([
                // Google answers a bare client with an interstitial that carries no
                // coordinates; with a browser UA it answers the redirect.
                'User-Agent' => 'Mozilla/5.0 (compatible; CR7Delivery/1.0)',
                'Accept-Language' => 'ar,en;q=0.8',
            ])
                ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                ->timeout(8)
                ->get($url);
        } catch (\Throwable) {
            return ApiResponse::error('map_link_unreachable', 'تعذّر فتح الرابط.');
        }

        $history = $response->getHeader('X-Guzzle-Redirect-History');
        $finalUrl = $history === [] ? $url : (string) end($history);

        $point = self::pointFromUrl($finalUrl)
            // A link that redirects to a consent page keeps its target in `continue=`;
            // and where the URL says nothing, the page itself still carries the pin.
            ?? self::pointFromUrl(urldecode($finalUrl))
            ?? self::pointFromUrl((string) $response->body());

        if ($point === null) {
            return ApiResponse::error('map_link_has_no_point', 'الرابط لا يحمل موقعًا يمكن قراءته.');
        }

        $payload = ['lat' => $point[0], 'lng' => $point[1], 'url' => $finalUrl];
        Cache::put($cacheKey, $payload, now()->addDay());

        return ApiResponse::success($payload);
    }

    /**
     * Only the map services, and only by exact host or a `.`-anchored suffix — a
     * `str_contains` check here would accept `google.com.attacker.example`.
     */
    private function isMapHost(string $host): bool
    {
        $allowed = [
            'goo.gl', 'app.goo.gl', 'g.co', 'google.com', 'maps.google.com',
            'apple.com', 'openstreetmap.org', 'osm.org', 'waze.com', 'bing.com',
        ];

        foreach ($allowed as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The most specific point a maps URL or page carries, or `null`.
     *
     * Mirrors `packages/ui/src/lib/map-link.ts`, and in the same order and for the same
     * reason: `!3d…!4d…` is the pin Google dropped on the place, `q=`/`ll=` is what the
     * sharer asked for, and `@…` is only where the camera was — often a block away.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function pointFromUrl(string $text): ?array
    {
        $number = '-?\d{1,3}(?:\.\d+)?';

        $patterns = [
            "/!3d({$number})!4d({$number})/",
            "/[?&](?:q|query|ll|sll|center|daddr|destination)=({$number}),\s*({$number})/i",
            "/[?&]mlat=({$number})[^&]*&(?:[^&]*&)?mlon=({$number})/i",
            "/[@\/]({$number}),({$number})(?:,|\/|z|\?|&|$)/",
            "/map=\d+(?:\.\d+)?\/({$number})\/({$number})/",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) !== 1) {
                continue;
            }

            $lat = (float) $matches[1];
            $lng = (float) $matches[2];

            // `0,0` is what a half-matched pattern produces. Silently moving a shop to
            // the Atlantic is worse than saying the link was not understood.
            if ($lat === 0.0 && $lng === 0.0) {
                continue;
            }

            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return [$lat, $lng];
            }
        }

        return null;
    }

    /**
     * `GET /geo/route?from_lat=&from_lng=&to_lat=&to_lng=` — the road between two
     * points, as a list of `[lat, lng]` the client draws directly.
     *
     * Operator, 2026-09-05: «عايزه بقى الخط ده يبقى في الشارع بالضبط، ماشي في الشارع
     * بالضبط اللي التعبان يمشي فيها، وكل لما الديليفري يغير مكانه… يقوم محدد الشارع
     * التاني اللي هيمشي فيه». Re-routing as the captain moves is the client's job and
     * costs nothing extra here: a new position is a new cache key, and a position that
     * has not really changed is the same key and the same answer.
     *
     * `route: null` is a complete, successful answer meaning «draw the straight line».
     * A directions outage must never be a screen the captain has to dismiss while they
     * are riding.
     */
    public function route(Request $request, Router $router): JsonResponse
    {
        $validated = $request->validate([
            'from_lat' => ['required', 'numeric', 'between:-90,90'],
            'from_lng' => ['required', 'numeric', 'between:-180,180'],
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $route = $router->route(
            (float) $validated['from_lat'],
            (float) $validated['from_lng'],
            (float) $validated['to_lat'],
            (float) $validated['to_lng'],
        );

        return ApiResponse::success(['route' => $route]);
    }

    /**
     * `GET /geo/places?lat=&lng=&radius_km=` — every shop near a point, by name.
     *
     * The raster basemaps under the pins are OpenStreetMap data, and OSM knows
     * roads far better than it knows which unit of a Gamasa parade is a
     * pharmacy. Operator, 2026-09-07: «عندك مثلاً كل الأماكن موجودة بحسب الاسم،
     * هتلاقي المطعم الحاجات دي». No tile set will ever answer that for this
     * town — but the platform can, because these are its own rows.
     *
     * Public, because it is the same catalogue `GET /restaurants` already
     * publishes to a signed-out browser, narrowed to four columns and a point.
     *
     * Cheap on purpose: a bounding box the index can use, an ordering by
     * distance, a hard limit, and a cache keyed on the point rounded to about
     * 110 m — a map that re-asks as it pans must not re-run the query per metre.
     */
    public function places(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'numeric', 'between:0.2,50'],
            'limit' => ['sometimes', 'integer', 'between:1,200'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $radius = (float) ($validated['radius_km'] ?? 5);
        $limit = (int) ($validated['limit'] ?? 60);

        $places = Cache::remember(
            sprintf('geo:places:%.3f:%.3f:%.1f:%d', $lat, $lng, $radius, $limit),
            now()->addMinutes(10),
            fn () => $this->nearbyShops($lat, $lng, $radius, $limit),
        );

        return ApiResponse::success(MapPlaceResource::collection($places), meta: [
            'radius_km' => $radius,
            'count' => $places->count(),
        ]);
    }

    /**
     * @return Collection<int, Restaurant>
     */
    private function nearbyShops(float $lat, float $lng, float $radius, int $limit)
    {
        // A box before the trigonometry: MySQL can range-scan `latitude` and
        // `longitude`, and cannot scan an `acos()`. One degree of latitude is
        // 111.32 km everywhere; a degree of longitude shrinks with the cosine
        // of the latitude, which at 31°N is about 0.855.
        $latDelta = $radius / 111.32;
        $lngDelta = $radius / max(0.000001, 111.32 * cos(deg2rad($lat)));

        return Restaurant::query()
            ->select(['id', 'slug', 'name_ar', 'name_en', 'latitude', 'longitude', 'store_category_id'])
            ->with('storeCategory:id,slug,name_ar,name_en,color,icon')
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->orderByRaw(
                '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))',
                [$lat, $lng, $lat],
            )
            ->limit($limit)
            ->get()
            // The box has corners the circle does not. Ordered by distance
            // already, so this only ever trims the far end.
            ->filter(fn (Restaurant $shop) => Geo::haversineKm(
                (float) $shop->latitude,
                (float) $shop->longitude,
                $lat,
                $lng,
            ) <= $radius)
            ->values();
    }

    /** Mapbox takes an ISO code; the app already sends `Accept-Language`. */
    private function language(Request $request): string
    {
        return str_starts_with(strtolower($request->header('Accept-Language', 'ar')), 'en') ? 'en' : 'ar';
    }
}
