<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ManagesProductGallery;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 72 — the admin mirror of the restaurant gallery endpoints, under
 * `/admin/restaurants/{restaurant}/products/{product}/gallery…`. The routes carry
 * `permission:products.update` and `scopeBindings()`; a restaurant outside the admin's scope
 * is a 404, as everywhere else in D-52.
 */
class ProductGalleryController extends Controller
{
    use ManagesProductGallery;

    public function store(Request $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        return $this->appendToGallery($request, $product, $restaurant);
    }

    public function destroy(Request $request, Restaurant $restaurant, Product $product, string $ref): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        return $this->removeFromGallery($request, $product, $ref);
    }

    public function order(Request $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        return $this->reorderGallery($request, $product);
    }

    private function ensureRestaurantInScope(Request $request, ?Restaurant $restaurant): void
    {
        abort_if($restaurant === null, 404);
        AdminScope::abortUnlessVisible(AdminScope::applyToRestaurants(Restaurant::query(), $request->user()), $restaurant);
    }
}
