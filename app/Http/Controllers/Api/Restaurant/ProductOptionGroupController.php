<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreOptionGroupRequest;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET/POST/PATCH/DELETE /restaurant/products/{id}/option-groups[/{gid}]. */
class ProductOptionGroupController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);

        return ApiResponse::success($product->optionGroups()->with('options')->orderBy('sort_order')->get());
    }

    public function store(StoreOptionGroupRequest $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);
        $group = $product->optionGroups()->create($request->validated());

        return ApiResponse::success($group, 'تم إنشاء مجموعة الخيارات بنجاح', status: 201);
    }

    public function update(StoreOptionGroupRequest $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureOwned($request, $product);
        abort_unless($optionGroup->product_id === $product->id, 404);
        $optionGroup->update($request->validated());

        return ApiResponse::success($optionGroup->fresh(), 'تم تحديث مجموعة الخيارات بنجاح');
    }

    public function destroy(Request $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureOwned($request, $product);
        abort_unless($optionGroup->product_id === $product->id, 404);
        $optionGroup->delete();

        return ApiResponse::success(message: 'تم حذف مجموعة الخيارات بنجاح');
    }

    private function ensureOwned(Request $request, Product $product): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $product->restaurant_id === $restaurant->id, 404);
    }
}
