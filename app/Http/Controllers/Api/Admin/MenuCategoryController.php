<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuCategoryRequest;
use App\Http\Requests\Admin\UpdateMenuCategoryRequest;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MenuCategoryController extends Controller
{
    public function index(Restaurant $restaurant): JsonResponse
    {
        $categories = $restaurant->menuCategories()->orderBy('sort_order')->get();

        return ApiResponse::success($categories);
    }

    public function store(StoreMenuCategoryRequest $request, Restaurant $restaurant): JsonResponse
    {
        $category = $restaurant->menuCategories()->create($request->validated());

        return ApiResponse::success($category, 'تم إنشاء القسم بنجاح', status: 201);
    }

    public function update(UpdateMenuCategoryRequest $request, Restaurant $restaurant, MenuCategory $menuCategory): JsonResponse
    {
        $menuCategory->update($request->validated());

        return ApiResponse::success($menuCategory, 'تم تحديث القسم بنجاح');
    }

    public function destroy(Restaurant $restaurant, MenuCategory $menuCategory): JsonResponse
    {
        $menuCategory->delete();

        return ApiResponse::success(message: 'تم حذف القسم بنجاح');
    }
}
