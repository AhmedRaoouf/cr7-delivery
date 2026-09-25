<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductDetailResource;
use App\Http\Resources\Customer\ProductResource;
use App\Models\Cuisine;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\ArabicSearch;
use App\Support\Lists\ListParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — GET /products?ids=, GET /products/{id}, GET /cuisines/{id}/products. */
class ProductController extends Controller
{
    /**
     * Restoring a saved basket, or re-ordering, needs many rows at once. Without this
     * the client pays one request per line.
     *
     * Unavailable rows are returned too, flagged `is_available: false` — the basket has
     * to be able to say which line went away, which it cannot do from a 404.
     */
    public function index(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->take(100)
            ->values();

        if ($ids->isEmpty()) {
            return ApiResponse::success([]);
        }

        $products = Product::query()
            ->with(['restaurant:id,name_ar,name_en', 'optionGroups.options'])
            ->whereIn('id', $ids)
            ->onMenu()
            ->get();

        return ApiResponse::success(ProductResource::collection($products));
    }

    /**
     * Every dish sold by every kitchen tagged with this cuisine, paginated.
     *
     * «ولما أدوس عليها أكني بسيرش يجيبلي كل البرجرز… يجيبلي المنتجات على طول، مش
     * مطاعم». A cuisine used to lead to `CUS-13`, a list of *restaurants* the customer
     * then had to open one by one to find a burger. This is the list they asked for.
     *
     * The cuisine lives on the restaurant, not on the dish — doc 03 has no
     * `cuisine_product` table and inventing one would mean tagging 562 rows by hand —
     * so the filter is a `whereHas` through the restaurant. `city_id` narrows it the
     * same way `GET /restaurants` does, because a customer in جمصة must not be offered
     * a dish from a kitchen that does not deliver to them.
     *
     * The restaurant is eager-loaded so `ProductResource` can render `restaurant_name`:
     * a dish out of its own menu is unopenable and unaddable without one.
     */
    public function byCuisine(Request $request, Cuisine $cuisine): JsonResponse
    {
        // «أخرى» is where dishes no filter fits are kept; it is not a filter.
        abort_unless($cuisine->is_active && ! $cuisine->is_fallback, 404);

        $cityId = $request->integer('city_id') ?: null;

        // Doc 82 — the dish's own cuisine, not its place's tags: «برجر» lists burgers,
        // not the drinks of a burger place, and finds a burger at a grill house too.
        $products = Product::query()
            ->with(['restaurant:id,name_ar,name_en,logo', 'optionGroups.options'])
            ->where('products.cuisine_id', $cuisine->id)
            ->orderableHere()
            ->when($cityId, fn ($q, $id) => $q->whereHas('restaurant', fn ($rq) => $rq->where('city_id', $id)))
            ->when(($search = ListParams::of($request)->search()) !== '', fn ($q) => ArabicSearch::apply(
                $q,
                ['name_ar', 'name_en', 'description_ar', 'description_en'],
                ArabicSearch::tokens($search),
            ))
            ->when($request->string('sort')->value(), function ($q, $sort) {
                match ($sort) {
                    'price' => $q->orderBy('price'),
                    '-price' => $q->orderByDesc('price'),
                    '-rating_avg' => $q->orderByDesc('rating_avg'),
                    default => $q->orderByDesc('sold_count'),
                };
            }, fn ($q) => $q->orderByDesc('is_featured')->orderByDesc('sold_count'))
            // A stable tiebreak. Without it MySQL is free to order two dishes with the
            // same `sold_count` differently on page 1 and page 2, which is how a paged
            // list shows the same row twice and drops another.
            ->orderBy('id')
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::success(ProductResource::collection($products->getCollection()), meta: [
            'current_page' => $products->currentPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'last_page' => $products->lastPage(),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->isOrderable(), 404);

        return ApiResponse::success(new ProductDetailResource($product->load('optionGroups.options')));
    }
}
