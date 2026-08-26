<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET /restaurant/ratings. */
class RatingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $ratings = $restaurant->ratings()
            ->where('is_hidden', false)
            ->with('user:id,name')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($ratings->items(), meta: [
            'current_page' => $ratings->currentPage(),
            'per_page' => $ratings->perPage(),
            'total' => $ratings->total(),
            'last_page' => $ratings->lastPage(),
        ]);
    }
}
