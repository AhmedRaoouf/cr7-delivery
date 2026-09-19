<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use FiltersLists;
    use HandlesUploads;

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $params = $this->listParams($request);
        $sort = $params->sort(['sort_order', 'name', 'price', 'sold_count', 'rating_avg', 'created_at'], 'sort_order');

        $query = $restaurant->products()->getQuery()->with('optionGroups.options');

        $this->whereInList($query, 'products.menu_category_id', $params->ids('menu_category_id'));

        foreach (['is_available', 'is_featured'] as $flag) {
            if (($value = $params->bool($flag)) !== null) {
                $query->where('products.'.$flag, $value);
            }
        }

        ListSearch::apply($query, $params->search(), ['names' => ['name_ar', 'name_en']]);

        $products = $sort->apply($query, ['name' => 'products.name_ar'])->paginate($params->perPage());
        $products->getCollection()->each->withGalleryMeta();

        return $this->paginated($products, sort: $sort);
    }

    public function show(Request $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        return ApiResponse::success($product->load('optionGroups.options')->withGalleryMeta());
    }

    public function store(StoreProductRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $product = $this->createProductWithGallery($restaurant, $request, $request->validated());

        return ApiResponse::success($product->withGalleryMeta(), 'تم إنشاء المنتج بنجاح', status: 201);
    }

    public function update(UpdateProductRequest $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $product = $this->updateProductWithGallery($request, $product, $request->validated());

        return ApiResponse::success($product->withGalleryMeta(), 'تم تحديث المنتج بنجاح');
    }

    public function toggleAvailability(Request $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $product->update(['is_available' => ! $product->is_available]);

        return ApiResponse::success($product, 'تم تحديث حالة توفر المنتج بنجاح');
    }

    public function destroy(Request $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $product->delete();

        return ApiResponse::success(message: 'تم حذف المنتج بنجاح');
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
