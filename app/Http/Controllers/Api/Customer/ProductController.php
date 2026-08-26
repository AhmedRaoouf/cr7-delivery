<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductDetailResource;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** doc 08 §4 — GET /products/{id}. */
class ProductController extends Controller
{
    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_available, 404);

        return ApiResponse::success(new ProductDetailResource($product->load('optionGroups.options')));
    }
}
