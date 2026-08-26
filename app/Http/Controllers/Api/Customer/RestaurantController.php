<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductResource;
use App\Http\Resources\Customer\RestaurantDetailResource;
use App\Http\Resources\Customer\RestaurantResource;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — customer catalog browsing. */
class RestaurantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->array('filter');

        $restaurants = Restaurant::query()
            ->with(['cuisines', 'serviceZone'])
            ->where('is_active', true)
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->integer('zone_id'), fn ($q, $zoneId) => $q->where('service_zone_id', $zoneId))
            ->when($filter['cuisine_id'] ?? null, fn ($q, $cuisineId) => $q->whereHas(
                'cuisines',
                fn ($cq) => $cq->where('cuisines.id', $cuisineId),
            ))
            ->when(($filter['min_rating'] ?? null) !== null, fn ($q) => $q->where('rating_avg', '>=', (float) $filter['min_rating']))
            ->when($filter['free_delivery'] ?? null, fn ($q) => $q->whereHas(
                'serviceZone',
                fn ($zq) => $zq->whereNotNull('free_delivery_over'),
            ))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->whereFullText(['name_ar', 'name_en'], $request->string('search')))
            ->when($request->string('sort')->value(), function ($q, $sort) {
                match ($sort) {
                    '-rating_avg' => $q->orderByDesc('rating_avg'),
                    'rating_avg' => $q->orderBy('rating_avg'),
                    default => $q->orderByDesc('is_featured')->orderBy('sort_order'),
                };
            }, fn ($q) => $q->orderByDesc('is_featured')->orderBy('sort_order'))
            ->paginate($request->integer('per_page', 20));

        $items = $restaurants->getCollection();

        if ($filter['open_now'] ?? null) {
            $items = $items->filter(fn (Restaurant $r) => $r->isOpenNow())->values();
        }

        return ApiResponse::success(RestaurantResource::collection($items), meta: [
            'current_page' => $restaurants->currentPage(),
            'per_page' => $restaurants->perPage(),
            'total' => $restaurants->total(),
            'last_page' => $restaurants->lastPage(),
        ]);
    }

    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->is_active, 404);

        return ApiResponse::success(new RestaurantDetailResource($restaurant->load(['cuisines', 'serviceZone', 'openingHours'])));
    }

    public function categories(Restaurant $restaurant): JsonResponse
    {
        $locale = Locale::current(request());

        $categories = $restaurant->menuCategories()
            ->where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->where('is_available', true)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->{"name_{$locale}"},
                'image' => $category->image,
                'products_count' => $category->products_count,
            ]);

        return ApiResponse::success($categories);
    }

    public function products(Request $request, Restaurant $restaurant): JsonResponse
    {
        $products = $restaurant->products()
            ->where('is_available', true)
            ->when($request->integer('category_id'), fn ($q, $categoryId) => $q->where('menu_category_id', $categoryId))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->whereFullText(['name_ar', 'name_en'], $request->string('search')))
            ->when($request->string('sort')->value(), function ($q, $sort) {
                match ($sort) {
                    'price' => $q->orderBy('price'),
                    '-price' => $q->orderByDesc('price'),
                    '-sold_count' => $q->orderByDesc('sold_count'),
                    default => $q->orderBy('sort_order'),
                };
            }, fn ($q) => $q->orderBy('sort_order'))
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::success(ProductResource::collection($products->getCollection()), meta: [
            'current_page' => $products->currentPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'last_page' => $products->lastPage(),
        ]);
    }

    public function bestSellers(Restaurant $restaurant): JsonResponse
    {
        $products = $restaurant->products()
            ->where('is_available', true)
            ->orderByDesc('sold_count')
            ->limit(5)
            ->get();

        return ApiResponse::success(ProductResource::collection($products));
    }

    public function reviews(Request $request, Restaurant $restaurant): JsonResponse
    {
        $ratings = $restaurant->ratings()
            ->with('user:id,name')
            ->where('is_hidden', false)
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($ratings->items(), meta: [
            'current_page' => $ratings->currentPage(),
            'per_page' => $ratings->perPage(),
            'total' => $ratings->total(),
            'last_page' => $ratings->lastPage(),
        ]);
    }
}
