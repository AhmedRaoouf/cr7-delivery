<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductDetailResource;
use App\Http\Resources\Customer\ProductResource;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — GET /products?ids=, GET /products/{id}. */
class ProductController extends Controller
{
    /**
     * Restoring a saved basket, or re-ordering, needs many rows at once. Without this
     * the client pays one request per line.
     *
     * Unavailable rows are returned too, flagged `is_available: false` — the basket has
     * to be able to say which line went away, which it cannot do from a 404.
     */
    public function index(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->take(100)
            ->values();

        if ($ids->isEmpty()) {
            return ApiResponse::success([]);
        }

        $products = Product::query()
            ->with('restaurant:id,name_ar,name_en')
            ->whereIn('id', $ids)
            ->get();

        return ApiResponse::success(ProductResource::collection($products));
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_available, 404);

        return ApiResponse::success(new ProductDetailResource($product->load('optionGroups.options')));
    }
}
