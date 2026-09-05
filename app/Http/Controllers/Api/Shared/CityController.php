<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\CityResource;
use App\Http\Resources\Shared\ServiceZoneResource;
use App\Models\City;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §2 — GET /cities, GET /cities/{id}/zones: active-only, no auth. */
class CityController extends Controller
{
    public function index(): JsonResponse
    {
        $cities = City::query()->where('is_active', true)->orderBy('sort_order')->get();

        return ApiResponse::success(CityResource::collection($cities));
    }

    /**
     * `?selectable=1` narrows the list to the operator's priced areas — what a customer
     * picks from. Unfiltered it still returns every active zone, because the dashboard
     * and `RES-27` both need the coverage circles too.
     */
    public function zones(Request $request, City $city): JsonResponse
    {
        $zones = $city->serviceZones()
            ->where('is_active', true)
            ->when($request->boolean('selectable'), fn ($q) => $q->where('is_selectable', true))
            ->orderBy('sort_order')
            ->orderBy('delivery_fee')
            ->get();

        return ApiResponse::success(ServiceZoneResource::collection($zones));
    }
}
