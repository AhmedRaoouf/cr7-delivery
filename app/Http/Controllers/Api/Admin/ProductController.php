<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use HandlesUploads;

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $products = $restaurant->products()
            ->with('optionGroups.options')
            ->when($request->integer('menu_category_id'), fn ($q, $categoryId) => $q->where('menu_category_id', $categoryId))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($products->items(), meta: [
            'current_page' => $products->currentPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'last_page' => $products->lastPage(),
        ]);
    }

    public function show(Request $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        return ApiResponse::success($product->load('optionGroups.options'));
    }

    public function store(StoreProductRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $data = $request->validated();
        $data = $this->withPublicUpload($data, $request, 'image', 'products');
        $data = $this->withPublicGallery($data, $request, 'gallery', 'products');

        $product = $restaurant->products()->create($data);

        return ApiResponse::success($product, 'تم إنشاء المنتج بنجاح', status: 201);
    }

    public function update(UpdateProductRequest $request, Restaurant $restaurant, Product $product): JsonResponse
    {
        $this->ensureRestaurantInScope($request, $restaurant);

        $data = $request->validated();
        $data = $this->withPublicUpload($data, $request, 'image', 'products', $product);
        $data = $this->withPublicGallery($data, $request, 'gallery', 'products', $product);

        $product->update($data);

        return ApiResponse::success($product, 'تم تحديث المنتج بنجاح');
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
