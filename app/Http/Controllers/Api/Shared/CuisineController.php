<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Cuisine;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §2 — GET /cuisines: cuisine chips, no auth. */
class CuisineController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Cuisine::query()->where('is_active', true)->orderBy('sort_order')->get());
    }
}
