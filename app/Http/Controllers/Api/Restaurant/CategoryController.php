<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreCategoryRequest;
use App\Models\MenuCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET/POST/PATCH/DELETE /restaurant/categories[/{id}]: self-service, scoped to the caller's own restaurant. */
class CategoryController extends Controller
{
    use HandlesUploads;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        return ApiResponse::success($restaurant->menuCategories()->orderBy('sort_order')->get());
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $data = $this->withPublicUpload($request->validated(), $request, 'image', 'categories');
        $category = $restaurant->menuCategories()->create($data);

        return ApiResponse::success($category, 'تم إنشاء القسم بنجاح', status: 201);
    }

    public function update(StoreCategoryRequest $request, MenuCategory $category): JsonResponse
    {
        $this->ensureOwned($request, $category);
        $data = $this->withPublicUpload($request->validated(), $request, 'image', 'categories');
        $category->update($data);

        return ApiResponse::success($category->fresh(), 'تم تحديث القسم بنجاح');
    }

    public function destroy(Request $request, MenuCategory $category): JsonResponse
    {
        $this->ensureOwned($request, $category);
        $category->delete();

        return ApiResponse::success(message: 'تم حذف القسم بنجاح');
    }

    private function ensureOwned(Request $request, MenuCategory $category): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $category->restaurant_id === $restaurant->id, 404);
    }
}
