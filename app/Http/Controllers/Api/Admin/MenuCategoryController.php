<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MediaField;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuCategoryRequest;
use App\Http\Requests\Admin\UpdateMenuCategoryRequest;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    use HandlesUploads;

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $categories = $restaurant->menuCategories()->orderBy('sort_order')->get();

        return ApiResponse::success($categories);
    }

    public function store(StoreMenuCategoryRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $data = $this->withPublicUpload($request->validated(), $request, 'image', 'categories', media: MediaField::CategoryImage);
        $category = $restaurant->menuCategories()->create($data);

        return ApiResponse::success($category, 'تم إنشاء القسم بنجاح', status: 201);
    }

    public function update(UpdateMenuCategoryRequest $request, Restaurant $restaurant, MenuCategory $menuCategory): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $data = $this->withPublicUpload($request->validated(), $request, 'image', 'categories', $menuCategory, MediaField::CategoryImage);
        $menuCategory->update($data);

        return ApiResponse::success($menuCategory, 'تم تحديث القسم بنجاح');
    }

    public function destroy(Request $request, Restaurant $restaurant, MenuCategory $menuCategory): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $menuCategory->delete();

        return ApiResponse::success(message: 'تم حذف القسم بنجاح');
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
