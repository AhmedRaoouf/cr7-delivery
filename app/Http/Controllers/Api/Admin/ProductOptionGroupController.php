<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductOptionGroupRequest;
use App\Http\Requests\Admin\UpdateProductOptionGroupRequest;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductOptionGroupController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return ApiResponse::success($product->optionGroups()->with('options')->orderBy('sort_order')->get());
    }

    public function store(StoreProductOptionGroupRequest $request, Product $product): JsonResponse
    {
        $group = $product->optionGroups()->create($request->validated());

        return ApiResponse::success($group, 'تم إنشاء مجموعة الخيارات بنجاح', status: 201);
    }

    public function update(UpdateProductOptionGroupRequest $request, Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $optionGroup->update($request->validated());

        return ApiResponse::success($optionGroup, 'تم تحديث مجموعة الخيارات بنجاح');
    }

    public function destroy(Product $product, ProductOptionGroup $optionGroup): JsonResponse
    {
        $optionGroup->delete();

        return ApiResponse::success(message: 'تم حذف مجموعة الخيارات بنجاح');
    }
}
