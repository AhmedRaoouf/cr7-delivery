<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreCategoryRequest;
use App\Models\MenuCategory;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET/POST/PATCH/DELETE /restaurant/categories[/{id}]: self-service, scoped to the caller's own restaurant. */
class CategoryController extends Controller
{
    use FiltersLists;
    use HandlesUploads;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);

        $query = $restaurant->menuCategories()->getQuery();

        if (($active = $params->bool('is_active')) !== null) {
            $query->where('menu_categories.is_active', $active);
        }

        // `RES-12`'s search box, which the endpoint never implemented until batch 57.
        ListSearch::apply($query, $params->search(), ['names' => ['name_ar', 'name_en']]);

        return ApiResponse::success($query->orderBy('sort_order')->orderBy('id')->get());
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
        $data = $this->withPublicUpload($request->validated(), $request, 'image', 'categories', $category);
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
