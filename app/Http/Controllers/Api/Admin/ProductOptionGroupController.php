<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductOptionGroupRequest;
use App\Http\Requests\Admin\UpdateProductOptionGroupRequest;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductOptionGroupController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        return ApiResponse::success($product->optionGroups()->with('options')->orderBy('sort_order')->get());
    }

    public function store(StoreProductOptionGroupRequest $request, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        $group = $product->optionGroups()->create($request->validated());

        return ApiResponse::success($group, 'تم إنشاء مجموعة الخيارات بنجاح', status: 201);
    }

    public function update(UpdateProductOptionGroupRequest $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        $optionGroup->update($request->validated());

        return ApiResponse::success($optionGroup, 'تم تحديث مجموعة الخيارات بنجاح');
    }

    public function destroy(Request $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        $optionGroup->delete();

        return ApiResponse::success(message: 'تم حذف مجموعة الخيارات بنجاح');
    }

    /**
     * Batch 67 — a restaurant's menu follows the restaurant's scope (D-52): the route's
     * parent is checked here, and `scopeBindings()` on the routes makes every child in
     * the path belong to that parent.
     */
    private function ensureRestaurantInScope(Request $request, ?Restaurant $restaurant): void
    {
        abort_if($restaurant === null, 404);
        AdminScope::abortUnlessVisible(AdminScope::applyToRestaurants(Restaurant::query(), $request->user()), $restaurant);
    }
}
