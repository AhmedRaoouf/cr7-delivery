<?php

namespace App\Services\Maps;

use App\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mapbox Directions v5, proxied.
 *
 * The tracking maps drew a straight segment from the captain to the door, and the
 * comment in `tracking_map.dart` defended it: «the platform has no directions service,
 * and a fake road would be a promise about a street the captain may not be on». The
 * first half of that stopped being true the day a Mapbox token was configured — the
 * **same** `pk.…` token that fetches the tiles also answers Directions, on the same
 * free tier. Nothing had to be bought and nothing has to be added to `.env`; the code
 * simply never asked.
 *
 * Proxied for the same three reasons the geocoder is: the token stays off the device,
 * one answer is cached for every viewer of a trip rather than fetched per viewer, and
 * an outage degrades to the straight line instead of an error.
 *
 * **The cache key is the point of this class.** A captain pings every few seconds and
 * both the customer's screen and the operator's fleet map redraw on each one. Keyed at
 * 3 decimals — about 110 m — a captain riding down a street reuses one answer for the
 * length of it and buys a new route only when they have actually moved somewhere else.
 * Without that rounding a single trip is hundreds of billed requests.
 *
 * @see https://docs.mapbox.com/api/navigation/directions/
 */
class MapboxRouter implements Router
{
    private const ENDPOINT = 'https://api.mapbox.com/directions/v5/mapbox/driving';

    /**
     * Past this, the two points are not a delivery — they are two cities. Gamasa to
     * 15 May is 200 km and no captain rides it, so a request for that pair is a bug
     * somewhere upstream and must not be paid for.
     */
    private const MAX_SPAN_KM = 60.0;

    public function isConfigured(): bool
    {
        return filled(config('maps.mapbox.token'));
    }

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if (Geo::haversineKm($fromLat, $fromLng, $toLat, $toLng) > self::MAX_SPAN_KM) {
            return null;
        }

        $key = sprintf(
            'geo:route:%.3f,%.3f:%.3f,%.3f',
            $fromLat, $fromLng, $toLat, $toLng,
        );

        return Cache::remember(
            $key,
            now()->addMinutes((int) config('maps.cache.route_minutes', 10)),
            fn () => $this->call($fromLat, $fromLng, $toLat, $toLng),
        );
    }

    /** @return array{geometry: list<array{0: float, 1: float}>, distance_km: float, duration_minutes: float}|null */
    private function call(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        try {
            $response = Http::timeout((int) config('maps.timeout_seconds', 6))
                ->get(self::ENDPOINT."/{$fromLng},{$fromLat};{$toLng},{$toLat}", [
                    'access_token' => config('maps.mapbox.token'),
                    // GeoJSON rather than the default polyline6: the clients draw a
                    // list of points and nothing in this codebase decodes a polyline.
                    'geometries' => 'geojson',
                    // `simplified` drops the detail that makes the line follow a
                    // corner — which is the whole reason for asking.
                    'overview' => 'full',
                    'alternatives' => 'false',
                    'steps' => 'false',
                ]);

            if (! $response->successful()) {
                Log::warning('mapbox directions failed', ['status' => $response->status()]);

                return null;
            }

            $route = $response->json('routes.0');

            if (! is_array($route)) {
                return null;
            }

            $coordinates = $route['geometry']['coordinates'] ?? [];

            if (! is_array($coordinates) || count($coordinates) < 2) {
                return null;
            }

            return [
                // Mapbox is [lng, lat]. Every map in this application is [lat, lng].
                'geometry' => array_map(
                    static fn (array $point) => [(float) $point[1], (float) $point[0]],
                    $coordinates,
                ),
                'distance_km' => round(((float) ($route['distance'] ?? 0)) / 1000, 3),
                'duration_minutes' => round(((float) ($route['duration'] ?? 0)) / 60, 1),
            ];
        } catch (Throwable $e) {
            Log::warning('mapbox directions threw', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
