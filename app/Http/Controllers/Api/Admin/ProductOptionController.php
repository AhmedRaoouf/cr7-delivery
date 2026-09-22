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
use App\Support\Catalog\OptionGroupWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The admin mirror of the place's options (doc 76 §2.8). */
class ProductOptionController extends Controller
{
    public function index(Request $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);

        return ApiResponse::success($this->options($optionGroup));
    }

    public function store(StoreProductOptionRequest $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);

        $option = OptionGroupWriter::saveOption($optionGroup, null, $request->validated());

        return ApiResponse::success($option, 'تم إنشاء الخيار بنجاح', status: 201);
    }

    public function update(UpdateProductOptionRequest $request, ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);

        $option = OptionGroupWriter::saveOption($optionGroup, $option, $request->validated());

        return ApiResponse::success($option, 'تم تحديث الخيار بنجاح');
    }

    public function order(Request $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        OptionGroupWriter::reorder($optionGroup->options()->get(), $request->input('ids'));

        return ApiResponse::success($this->options($optionGroup), 'تم حفظ الترتيب');
    }

    public function destroy(Request $request, ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $optionGroup->product?->restaurant);

        $option->delete();

        return ApiResponse::success(message: 'تم حذف الخيار بنجاح');
    }

    private function options(ProductOptionGroup $optionGroup)
    {
        return $optionGroup->options()->orderBy('sort_order')->orderBy('id')->get();
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
