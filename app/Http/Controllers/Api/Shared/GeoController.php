<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\ServiceZone;
use App\Services\Maps\Geocoder;
use App\Services\Maps\Router;
use App\Support\ApiResponse;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $zone = ServiceZone::query()
            ->with('city')
            ->where('is_active', true)
            ->get()
            ->first(function (ServiceZone $zone) use ($lat, $lng) {
                if ($zone->polygon) {
                    return Geo::pointInPolygon($lat, $lng, $zone->polygon[0] ?? $zone->polygon);
                }

                if ($zone->radius_km) {
                    return Geo::haversineKm((float) $zone->center_lat, (float) $zone->center_lng, $lat, $lng) <= (float) $zone->radius_km;
                }

                return false;
            });

        if (! $zone) {
            return ApiResponse::success(['city' => null, 'zone' => null, 'is_serviceable' => false]);
        }

        return ApiResponse::success([
            'city' => $zone->city,
            'zone' => $zone,
            'is_serviceable' => true,
        ]);
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

    /** Mapbox takes an ISO code; the app already sends `Accept-Language`. */
    private function language(Request $request): string
    {
        return str_starts_with(strtolower($request->header('Accept-Language', 'ar')), 'en') ? 'en' : 'ar';
    }
}
