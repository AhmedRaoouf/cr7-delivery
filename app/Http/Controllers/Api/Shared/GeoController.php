<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\ServiceZone;
use App\Support\ApiResponse;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §2 — POST /geo/resolve: {lat,lng} -> {city, zone, is_serviceable}. */
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
}
