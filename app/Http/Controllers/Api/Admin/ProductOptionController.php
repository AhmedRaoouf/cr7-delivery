<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductOptionRequest;
use App\Http\Requests\Admin\UpdateProductOptionRequest;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductOptionController extends Controller
{
    public function store(StoreProductOptionRequest $request, ProductOptionGroup $optionGroup): JsonResponse
    {
        $option = $optionGroup->options()->create($request->validated());

        return ApiResponse::success($option, 'تم إنشاء الخيار بنجاح', status: 201);
    }

    public function update(UpdateProductOptionRequest $request, ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $option->update($request->validated());

        return ApiResponse::success($option, 'تم تحديث الخيار بنجاح');
    }

    public function destroy(ProductOptionGroup $optionGroup, ProductOption $option): JsonResponse
    {
        $option->delete();

        return ApiResponse::success(message: 'تم حذف الخيار بنجاح');
    }
}
