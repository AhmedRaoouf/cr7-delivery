<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §2 — GET /cities, GET /cities/{id}/zones: active-only, no auth. */
class CityController extends Controller
{
    public function index(): JsonResponse
    {
        $cities = City::query()->where('is_active', true)->orderBy('sort_order')->get();

        return ApiResponse::success($cities);
    }

    public function zones(City $city): JsonResponse
    {
        $zones = $city->serviceZones()->where('is_active', true)->get();

        return ApiResponse::success($zones);
    }
}
