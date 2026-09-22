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
use App\Support\Catalog\OptionGroupWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin mirror of the place's option groups (doc 76 §2.8): the same writer, with
 * the place checked against the admin's scope first.
 */
class ProductOptionGroupController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        return ApiResponse::success($this->groups($product));
    }

    public function store(StoreProductOptionGroupRequest $request, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        $group = OptionGroupWriter::create($product, $request->validated());

        return ApiResponse::success($group, 'تم إنشاء مجموعة الخيارات بنجاح', status: 201);
    }

    public function update(UpdateProductOptionGroupRequest $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        $group = OptionGroupWriter::update($optionGroup, $request->validated());

        return ApiResponse::success($group, 'تم تحديث مجموعة الخيارات بنجاح');
    }

    public function order(Request $request, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        OptionGroupWriter::reorder($product->optionGroups()->get(), $request->input('ids'));

        return ApiResponse::success($this->groups($product), 'تم حفظ الترتيب');
    }

    public function destroy(Request $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $product->restaurant);

        $optionGroup->delete();

        return ApiResponse::success(message: 'تم حذف مجموعة الخيارات بنجاح');
    }

    private function groups(Product $product)
    {
        return $product->optionGroups()
            ->with(['options' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
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
