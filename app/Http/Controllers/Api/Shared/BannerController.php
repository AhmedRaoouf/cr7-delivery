<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §2 — GET /banners?city_id=: home banners, no auth. */
class BannerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $now = now();

        $banners = Banner::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where(
                fn ($cq) => $cq->whereNull('city_ids')->orWhereJsonContains('city_ids', $cityId),
            ))
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($banners);
    }
}
