<?php

namespace App\Http\Controllers\Api\Shared;

use App\Enums\BannerAudience;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Support\ApiResponse;
use App\Support\FirstOrderPerk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §2 — GET /banners?city_id=: home banners, no auth. */
class BannerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $now = now();

        // The route is public — the app browses before it signs in — but a signed-in
        // request still carries its token, and whether this account has ordered before
        // decides which offers are true for it. Resolved explicitly rather than by
        // middleware so a signed-out visitor still gets the general banners.
        $user = auth('sanctum')->user();
        $audiences = [BannerAudience::All->value];
        if ($user !== null) {
            $audiences[] = FirstOrderPerk::appliesTo($user)
                ? BannerAudience::FirstOrder->value
                : BannerAudience::Returning->value;
        }

        $banners = Banner::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            // A slide promising a new account free delivery, shown to somebody who
            // will be charged for it, is worse than no slide at all.
            ->whereIn('audience', $audiences)
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where(
                fn ($cq) => $cq->whereNull('city_ids')->orWhereJsonContains('city_ids', $cityId),
            ))
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($banners);
    }
}
