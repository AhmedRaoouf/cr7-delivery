<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductResource;
use App\Http\Resources\Customer\RestaurantDetailResource;
use App\Http\Resources\Customer\RestaurantResource;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Support\ApiResponse;
use App\Support\ArabicSearch;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — customer catalog browsing. */
class RestaurantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->array('filter');
        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;

        $restaurants = Restaurant::query()
            ->with(['cuisines', 'serviceZone', 'storeCategory'])
            ->where('is_active', true)
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->integer('zone_id'), fn ($q, $zoneId) => $q->where('service_zone_id', $zoneId))
            // The home strip's filter. Accepts the id or the slug, because the app
            // deep-links «مطاعم» by slug and the dashboard's own list filters by id.
            ->when($filter['store_category'] ?? $filter['store_category_id'] ?? null, fn ($q, $category) => $q
                ->whereHas('storeCategory', fn ($cq) => is_numeric($category)
                    ? $cq->where('store_categories.id', (int) $category)
                    : $cq->where('store_categories.slug', $category)))
            ->when($filter['cuisine_id'] ?? null, fn ($q, $cuisineId) => $q->whereHas(
                'cuisines',
                fn ($cq) => $cq->where('cuisines.id', $cuisineId),
            ))
            ->when(($filter['min_rating'] ?? null) !== null, fn ($q) => $q->where('rating_avg', '>=', (float) $filter['min_rating']))
            ->when($filter['free_delivery'] ?? null, fn ($q) => $q->whereHas(
                'serviceZone',
                fn ($zq) => $zq->whereNotNull('free_delivery_over'),
            ))
            // D-60 — the band is the menu's own median, recomputed by the catalogue,
            // never hand-set. A restaurant with no published menu carries null and
            // answers no chip rather than defaulting into «متوسط».
            ->when($filter['price_band'] ?? null, fn ($q, $band) => $q->where('price_band', $band))
            ->when(($filter['max_delivery_fee'] ?? null) !== null, fn ($q) => $q->whereHas(
                'serviceZone',
                fn ($zq) => $zq->where('delivery_fee', '<=', (float) $filter['max_delivery_fee']),
            ))
            // Same folding + substring match `GET /search` uses — see `ArabicSearch`.
            // A full-text index answers on whole words, so «كشر» found nothing.
            ->when($request->string('search')->isNotEmpty(), fn ($q) => ArabicSearch::apply(
                $q,
                ['name_ar', 'name_en', 'description_ar', 'description_en'],
                ArabicSearch::tokens((string) $request->string('search')->value()),
            ))
            ->when($request->string('sort')->value(), function ($q, $sort) use ($lat, $lng) {
                match ($sort) {
                    '-rating_avg' => $q->orderByDesc('rating_avg'),
                    'rating_avg' => $q->orderBy('rating_avg'),
                    // «الأكثر طلبًا»
                    '-orders_count', 'popular' => $q->orderByDesc('orders_count'),
                    // «الأسرع» — the zone's own average, not a guess per restaurant.
                    // A correlated subquery rather than a join: joining `service_zones`
                    // makes the `is_active` above ambiguous, and both tables have one.
                    'fastest' => $q->orderBy(
                        ServiceZone::query()
                            ->select('avg_delivery_minutes')
                            ->whereColumn('service_zones.id', 'restaurants.service_zone_id'),
                    ),
                    // «الأقرب» — needs a point to be near. Without one it is not a
                    // sort at all, so it falls through to the default rather than
                    // silently ordering by something else.
                    'nearest' => ($lat !== null && $lng !== null)
                        ? $q->orderByRaw(
                            '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))',
                            [$lat, $lng, $lat],
                        )
                        : $q->orderByDesc('is_featured')->orderBy('sort_order'),
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
            ->when($request->string('search')->isNotEmpty(), fn ($q) => ArabicSearch::apply(
                $q,
                ['name_ar', 'name_en', 'description_ar', 'description_en'],
                ArabicSearch::tokens((string) $request->string('search')->value()),
            ))
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
