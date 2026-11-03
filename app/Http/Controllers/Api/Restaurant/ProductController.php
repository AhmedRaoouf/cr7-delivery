<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\StoreRestaurantProductRequest;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\Catalog\MenuWriter;
use App\Support\Catalog\PriceApproval;
use App\Support\Lists\ListSearch;
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
        $params = $this->listParams($request);
        $sort = $params->sort(['sort_order', 'name', 'price', 'sold_count', 'created_at'], 'sort_order');

        $query = $restaurant->products()->getQuery()->with(['optionGroups.options', 'openPriceRequest', 'recentPriceDecision']);

        $this->whereInList($query, 'products.menu_category_id', $params->ids('menu_category_id'));

        foreach (['is_available', 'is_featured'] as $flag) {
            if (($value = $params->bool($flag)) !== null) {
                $query->where('products.'.$flag, $value);
            }
        }

        // `RES-14`'s search box, which the endpoint never implemented until batch 57: an
        // operator looking for one dish in a 200-row menu got the whole menu back.
        ListSearch::apply($query, $params->search(), ['names' => ['name_ar', 'name_en']]);

        $products = $sort->apply($query, ['name' => 'products.name_ar'])->paginate($params->perPage(30));
        $products->getCollection()->each(fn (Product $product) => $product->withGalleryMeta()->withPriceReview());

        return $this->paginated($products, sort: $sort);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);

        return ApiResponse::success($product->load('optionGroups.options')->withGalleryMeta()->withPriceReview());
    }

    public function store(StoreRestaurantProductRequest $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        // doc 99 §1 — a new dish has no «before»: its price goes live as it always did.
        $data = $request->validated();
        unset($data['price_note']);

        $product = $this->createProductWithGallery($restaurant, $request, $data);

        return ApiResponse::success($product->withGalleryMeta()->withPriceReview(), 'تم إنشاء المنتج بنجاح', status: 201);
    }

    public function update(StoreRestaurantProductRequest $request, Product $product): JsonResponse
    {
        $this->ensureOwned($request, $product);

        $data = $request->validated();
        $note = $data['price_note'] ?? null;
        unset($data['price_note']);

        // doc 99 §1 — D-169. Every other field saves now; a changed price waits for the
        // office and never touches `products` until it is approved.
        $change = null;

        if (PriceApproval::enabled()) {
            $change = PriceApproval::productChange($product, $data);
            unset($data['price'], $data['discount_price']);
        }

        $product = $this->updateProductWithGallery($request, $product, $data);

        $filed = $change === null ? null : PriceApproval::file($product, $request->user(), $change, note: $note);

        $payload = $product->fresh()->withGalleryMeta()->withPriceReview();

        if ($filed === null) {
            return ApiResponse::success($payload, 'تم تحديث المنتج بنجاح');
        }

        return ApiResponse::success(
            $payload,
            'أُرسل السعر الجديد إلى الإدارة للمراجعة، وسيظهر للعملاء بعد الاعتماد',
            meta: ['price_request' => $filed['outcome']],
        );
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

        return ApiResponse::success($product->fresh()->withPriceReview(), 'تم تحديث حالة التوفر');
    }

    public function bulkAvailability(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $count = MenuWriter::setAvailability($restaurant, $request);

        return ApiResponse::success(['updated' => $count], 'تم تحديث حالة التوفر للمنتجات المحددة');
    }

    /** Doc 77 — `POST /restaurant/products/bulk-delete` {ids}. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $count = MenuWriter::deleteProducts($restaurant, $request);

        return ApiResponse::success(['deleted' => $count], "تم حذف {$count} صنف");
    }

    private function ensureOwned(Request $request, Product $product): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $product->restaurant_id === $restaurant->id, 404);
    }
}
