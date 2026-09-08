<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\HomePopupResource;
use App\Models\HomePopup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * doc 08 §2 — GET /home-popups: «العرض المنبثق», the card the home screen opens with.
 *
 * Unauthenticated, beside `/banners` and `/store-categories`, and for the same reason:
 * the app browses before it signs in («هو بيخش عادي على الواجهة وبيتصفح»), and this is
 * drawn over the first screen it shows.
 *
 * **A list, not a card.** «ممكن يظهر كرت، يعمل X يظهر واحد تاني، يظهر كذا واحد يعني ورا
 * بعض» — the queue is returned whole, in the order the operator arranged it, and the app
 * walks it. Which of them a given customer has already seen is `frequency` plus what the
 * device remembers; the server does not track a dismissal per user, because that would
 * make an anonymous browser — the one this endpoint exists for — untrackable anyway.
 */
class HomePopupController extends Controller
{
    public function index(): JsonResponse
    {
        $popups = HomePopup::query()
            ->live()
            // The resource resolves a `category` popup to the category's slug.
            ->with('storeCategory:id,slug')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(HomePopupResource::collection($popups));
    }
}
