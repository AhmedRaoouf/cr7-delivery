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
        // Doc 82 — only what a tap can fill: «أخرى» and an empty cuisine are not offered.
        $cuisines = Cuisine::query()
            ->offered()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(CuisineResource::collection($cuisines));
    }
}
