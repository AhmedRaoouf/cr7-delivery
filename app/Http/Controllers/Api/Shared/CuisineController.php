<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\CuisineResource;
use App\Models\Cuisine;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §2 — GET /cuisines: cuisine chips, no auth. */
class CuisineController extends Controller
{
    public function index(): JsonResponse
    {
        $cuisines = Cuisine::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(CuisineResource::collection($cuisines));
    }
}
