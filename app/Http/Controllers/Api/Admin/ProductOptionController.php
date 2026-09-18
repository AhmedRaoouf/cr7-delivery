<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductOptionRequest;
use App\Http\Requests\Admin\UpdateProductOptionRequest;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductOptionController extends Controller
{
    public function store(StoreProductOptionRequest $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);

        $option = $optionGroup->options()->create($request->validated());

        return ApiResponse::success($option, 'تم إنشاء الخيار بنجاح', status: 201);
    }

    public function update(UpdateProductOptionRequest $request, ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);

        $option->update($request->validated());

        return ApiResponse::success($option, 'تم تحديث الخيار بنجاح');
    }

    public function destroy(Request $request, ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);

        $option->delete();

        return ApiResponse::success(message: 'تم حذف الخيار بنجاح');
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
