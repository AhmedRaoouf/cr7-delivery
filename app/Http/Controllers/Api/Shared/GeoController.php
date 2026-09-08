<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\MapPlaceResource;
use App\Http\Resources\Shared\ServiceZoneResource;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Services\Maps\Geocoder;
use App\Services\Maps\Router;
use App\Support\ApiResponse;
use App\Support\Geo;
use App\Support\ZoneLocator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        $zone = ZoneLocator::forPoint($lat, $lng);

        if ($zone) {
            return ApiResponse::success([
                'city' => $zone->city,
                'zone' => $zone,
                'is_serviceable' => true,
                'requires_zone_choice' => ! $zone->is_selectable,
                'zones' => ServiceZoneResource::collection($this->pricedZones($zone->city_id)),
            ]);
        }

        // D-70 — the priced areas from the operator's flyer are names, not shapes: no
        // geocoder can place «جنة 3» or «الصناعية الرابعة», so they carry no radius and
        // no point ever falls inside one. Coverage is therefore the **city** circle, and
        // the price is whichever area the customer picks off the list this returns.
        // Without this fallback every address in New Mansoura resolved to «خارج نطاق
        // التوصيل» the moment the wide legacy circles stopped being the price.
        $city = ZoneLocator::cityForPoint($lat, $lng);

        if (! $city) {
            return ApiResponse::success([
                'city' => null,
                'zone' => null,
                'is_serviceable' => false,
                'requires_zone_choice' => false,
                'zones' => [],
            ]);
        }

        return ApiResponse::success([
            'city' => $city,
            // Deliberately null. The pin says which town; only the customer can say
            // which area of it, and the fee follows that answer, not this one.
            'zone' => null,
            'is_serviceable' => true,
            'requires_zone_choice' => true,
            'zones' => ServiceZoneResource::collection($this->pricedZones($city->id)),
        ]);
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
            'neighborhood' => $result['neighborhood'] ?? null,
            'city' => $result['city'] ?? null,
        ]);
    }

    /**
     * `GET /geo/search?q=&lat=&lng=` — free-text place lookup, biased to where
     * the customer is standing so «الكورنيش» means this town's.
     */
    public function search(Request $request, Geocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return ApiResponse::success($geocoder->search(
            $validated['q'],
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
            $this->language($request),
        ));
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
