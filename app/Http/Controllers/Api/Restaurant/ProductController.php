<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreRestaurantProductRequest;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET/POST/PATCH/DELETE /restaurant/products[/{id}]: self-service, own restaurant only. */
class ProductController extends Controller
{
    use FiltersLists;
    use HandlesUploads;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $products = $restaurant->products()
            ->with('optionGroups.options')
            ->when($this->filterInt($request, 'menu_category_id'), fn ($q, $id) => $q->where('menu_category_id', $id))
            // `RES-14`'s search box, which the endpoint never implemented: an operator
            // looking for one dish in a 200-row menu got the whole menu back.
            ->when($this->filterString($request, 'search') !== '', function ($q) use ($request) {
                $term = '%'.$this->filterString($request, 'search').'%';
                $q->where(fn ($sq) => $sq->where('name_ar', 'like', $term)->orWhere('name_en', 'like', $term));
            })
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::success($products->items(), meta: [
            'current_page' => $products->currentPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'last_page' => $products->lastPage(),
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);

        return ApiResponse::success($product->load('optionGroups.options'));
    }

    public function store(StoreRestaurantProductRequest $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $data = $request->validated();
        $data = $this->withPublicUpload($data, $request, 'image', 'products');
        $data = $this->withPublicGallery($data, $request, 'gallery', 'products');

        $product = $restaurant->products()->create($data);

        return ApiResponse::success($product, 'تم إنشاء المنتج بنجاح', status: 201);
    }

    public function update(StoreRestaurantProductRequest $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);

        $data = $request->validated();
        $data = $this->withPublicUpload($data, $request, 'image', 'products', $product);
        $data = $this->withPublicGallery($data, $request, 'gallery', 'products', $product);

        $product->update($data);

        return ApiResponse::success($product->fresh(), 'تم تحديث المنتج بنجاح');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);
        $product->delete();

        return ApiResponse::success(message: 'تم حذف المنتج بنجاح');
    }

    public function availability(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);
        $validated = $request->validate(['is_available' => ['required', 'boolean']]);
        $product->update($validated);

        return ApiResponse::success($product->fresh(), 'تم تحديث حالة التوفر');
    }

    public function bulkAvailability(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'is_available' => ['required', 'boolean'],
        ]);

        $restaurant->products()->whereIn('id', $validated['ids'])->update(['is_available' => $validated['is_available']]);

        return ApiResponse::success(message: 'تم تحديث حالة التوفر للمنتجات المحددة');
    }

    private function ensureOwned(Request $request, Product $product): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $product->restaurant_id === $restaurant->id, 404);
    }
}
