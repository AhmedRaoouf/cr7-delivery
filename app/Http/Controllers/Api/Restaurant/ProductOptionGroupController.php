<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreOptionGroupRequest;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Support\ApiResponse;
use App\Support\Catalog\OptionGroupWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 08 §6 — GET/POST/PATCH/DELETE /restaurant/products/{id}/option-groups[/{gid}],
 * PUT …/option-groups/order. The rules are {@see OptionGroupWriter}'s (doc 76 §2).
 */
class ProductOptionGroupController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);

        return ApiResponse::success($this->groups($product));
    }

    public function store(StoreOptionGroupRequest $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);
        $group = OptionGroupWriter::create($product, $request->validated());

        return ApiResponse::success($group, 'تم إنشاء مجموعة الخيارات بنجاح', status: 201);
    }

    public function update(StoreOptionGroupRequest $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureOwned($request, $product);
        abort_unless($optionGroup->product_id === $product->id, 404);
        $group = OptionGroupWriter::update($optionGroup, $request->validated());

        return ApiResponse::success($group, 'تم تحديث مجموعة الخيارات بنجاح');
    }

    public function order(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        OptionGroupWriter::reorder($product->optionGroups()->get(), $request->input('ids'));

        return ApiResponse::success($this->groups($product), 'تم حفظ الترتيب');
    }

    public function destroy(Request $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $this->ensureOwned($request, $product);
        abort_unless($optionGroup->product_id === $product->id, 404);
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

    private function ensureOwned(Request $request, Product $product): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $product->restaurant_id === $restaurant->id, 404);
    }
}
