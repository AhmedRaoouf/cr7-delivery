<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\ManagesProductGallery;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 72 — `POST|DELETE|PUT /restaurant/products/{id}/gallery…`: one picture at a time,
 * for the caller's own products only (a rival's product is a 404).
 */
class ProductGalleryController extends Controller
{
    use ManagesProductGallery;

    public function store(Request $request, Product $product): JsonResponse
    {
        return $this->appendToGallery($request, $this->owned($request, $product), $request->user()->restaurant);
    }

    public function destroy(Request $request, Product $product, string $ref): JsonResponse
    {
        return $this->removeFromGallery($request, $this->owned($request, $product), $ref);
    }

    public function order(Request $request, Product $product): JsonResponse
    {
        return $this->reorderGallery($request, $this->owned($request, $product));
    }

    private function owned(Request $request, Product $product): Product
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $product->restaurant_id === $restaurant->id, 404);

        return $product;
    }
}
