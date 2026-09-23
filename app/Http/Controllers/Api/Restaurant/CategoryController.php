<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Enums\MediaField;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreCategoryRequest;
use App\Models\MenuCategory;
use App\Support\ApiResponse;
use App\Support\Catalog\MenuWriter;
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

        // Doc 77 — the row says how many dishes it holds, so «حذف» can say what happens to them.
        $query = $restaurant->menuCategories()->withCount('products')->getQuery();

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
        $data = $this->withPublicUpload($request->validated(), $request, 'image', 'categories', media: MediaField::CategoryImage);
        $category = $restaurant->menuCategories()->create($data);

        return ApiResponse::success($category, 'تم إنشاء القسم بنجاح', status: 201);
    }

    public function update(StoreCategoryRequest $request, MenuCategory $category): JsonResponse
    {
        $this->ensureOwned($request, $category);
        $data = $this->withPublicUpload($request->validated(), $request, 'image', 'categories', $category, MediaField::CategoryImage);
        $category->update($data);

        return ApiResponse::success($category->fresh(), 'تم تحديث القسم بنجاح');
    }

    public function destroy(Request $request, MenuCategory $category): JsonResponse
    {
        $this->ensureOwned($request, $category);
        $result = MenuWriter::deleteCategory($request->user()->restaurant, $category, $request);

        return ApiResponse::success($result, 'تم حذف القسم بنجاح');
    }

    /** Doc 77 — `PUT /restaurant/categories/order` {ids}: every category, in the new order. */
    public function order(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        MenuWriter::orderCategories($restaurant, $request);

        return ApiResponse::success($restaurant->menuCategories()->withCount('products')->orderBy('sort_order')->orderBy('id')->get(), 'تم حفظ الترتيب');
    }

    /** Doc 77 — `PUT /restaurant/categories/{category}/products/order` {ids}: the dishes inside one category. */
    public function productsOrder(Request $request, MenuCategory $category): JsonResponse
    {
        $this->ensureOwned($request, $category);
        MenuWriter::orderProducts($category, $request);

        return ApiResponse::success(message: 'تم حفظ الترتيب');
    }

    private function ensureOwned(Request $request, MenuCategory $category): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $category->restaurant_id === $restaurant->id, 404);
    }
}
