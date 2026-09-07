<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\StoreCategoryResource;
use App\Models\StoreCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * doc 08 §2 — GET /store-categories: the home screen's top strip, no auth.
 *
 * Unauthenticated on purpose. The app now browses before it signs in («هو بيخش عادي على
 * الواجهة وبيتصفح»), and the first thing it draws is this.
 */
class StoreCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = StoreCategory::query()
            ->where('is_active', true)
            // Empty categories are listed too, and the count says so.
            //
            // The first instinct was to hide them — a card that leads nowhere is a card
            // the customer taps once. But the operator names the strip before they fill
            // it («ممكن يضيف كمان واحدة مثلاً فواكه»), and a category that silently
            // refuses to appear until somebody assigns a shop to it reads as the
            // dashboard being broken. `is_active` is the switch for hiding one; the app
            // draws an empty state behind a category with `restaurants_count = 0`.
            ->withCount(['restaurants' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(StoreCategoryResource::collection($categories));
    }
}
