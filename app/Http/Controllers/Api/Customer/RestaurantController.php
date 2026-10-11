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
use App\Support\Catalog\PlaceOrder;
use App\Support\Lists\ListParams;
use App\Support\Locale;
use App\Support\MenuCategoryIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — customer catalog browsing. */
class RestaurantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->array('filter');
        // `search` or `filter[search]` (ADMIN-LISTS.md §0.1).
        $search = ListParams::of($request)->search();
        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;

        $restaurants = Restaurant::query()
            // `openingHours` too (batch 68): `is_open_now` on every card read today's row
            // with a query of its own.
            ->with(['cuisines', 'serviceZone', 'storeCategory', 'openingHours'])
            ->where('is_active', true)
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->integer('zone_id'), fn ($q, $zoneId) => $q->where('service_zone_id', $zoneId))
            // The home strip's filter. Accepts the id or the slug, because the app
            // deep-links «مطاعم» by slug and the dashboard's own list filters by id.
            //
            // And accepts **several**, comma-separated. Operator, 2026-09-08: «لو
            // دوست على مطاعم وكافيهات، يظهر المطاعم والكافيهات» — the home cards are
            // a multi-select now, so the filter is a set and the match is an OR.
            // One value still behaves exactly as it always did.
            ->when($filter['store_category'] ?? $filter['store_category_id'] ?? null, function ($q, $category) {
                $wanted = collect(is_array($category) ? $category : explode(',', (string) $category))
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->values();

                if ($wanted->isEmpty()) {
                    return $q;
                }

                $ids = $wanted->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (int) $value);
                $slugs = $wanted->reject(fn ($value) => is_numeric($value));

                return $q->whereHas('storeCategory', fn ($cq) => $cq
                    ->where(function ($inner) use ($ids, $slugs) {
                        if ($ids->isNotEmpty()) {
                            $inner->orWhereIn('store_categories.id', $ids->all());
                        }
                        if ($slugs->isNotEmpty()) {
                            $inner->orWhereIn('store_categories.slug', $slugs->all());
                        }
                    }));
            })
            // Doc 82 — a place answers a cuisine when it serves a dish of it, or when it
            // is tagged with it (the tag is how a place with no menu yet still shows).
            ->when($filter['cuisine_id'] ?? null, fn ($q, $cuisineId) => $q->where(fn ($inner) => $inner
                ->whereHas('products', fn ($pq) => $pq->where('products.cuisine_id', $cuisineId)
                    ->where('products.is_available', true)->onMenu())
                ->orWhereHas('cuisines', fn ($cq) => $cq->where('cuisines.id', $cuisineId))))
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
            ->when($search !== '', fn ($q) => ArabicSearch::apply(
                $q,
                ['name_ar', 'name_en', 'description_ar', 'description_en'],
                ArabicSearch::tokens($search),
            ))
            // Batch 68 — in SQL, so the page is full and `meta.total` counts only open
            // places. It used to drop the closed ones from each page after the fact: a page
            // of twenty came back with six and a total that still counted all forty.
            ->when($filter['open_now'] ?? null, fn ($q) => $q->openNow())
            // Doc 87 — no sort chosen: the operator's order (pinned places, then the
            // preset on the admin panel). A sort the customer picked is theirs, whole.
            ->when(PlaceOrder::isRequested($request), fn ($q) => PlaceOrder::apply($q), function ($q) use ($request, $lat, $lng) {
                match ($request->string('sort')->value()) {
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
                    // sort at all, so it falls through to the operator's order rather
                    // than silently ordering by something else.
                    'nearest' => ($lat !== null && $lng !== null)
                        ? $q->orderByRaw(
                            '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))',
                            [$lat, $lng, $lat],
                        )
                        : PlaceOrder::apply($q),
                    default => PlaceOrder::apply($q),
                };
                // Equal distances or ratings fall back to a stable order, so a page
                // boundary never repeats or skips a place.
                $q->orderBy('restaurants.id');
            })
            ->paginate($request->integer('per_page', 20));

        $items = $restaurants->getCollection();

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

        return ApiResponse::success(new RestaurantDetailResource($restaurant->load(['cuisines', 'serviceZone', 'openingHours', 'storeCategory'])));
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
                // Resolved, never stored — see `MenuCategoryIcon`. The operator's
                // own upload wins; otherwise the tab gets the icon its name earns,
                // including for a category created after this build shipped.
                'image' => MenuCategoryIcon::resolve(
                    $category->image,
                    $category->name_ar,
                    $category->name_en,
                ),
                'products_count' => $category->products_count,
            ]);

        return ApiResponse::success($categories);
    }

    public function products(Request $request, Restaurant $restaurant): JsonResponse
    {
        $params = ListParams::of($request);
        $search = $params->search();

        $products = $restaurant->products()
            ->with('optionGroups.options')
            ->where('is_available', true)
            ->onMenu()
            ->when($params->id('category_id'), fn ($q, $categoryId) => $q->where('menu_category_id', $categoryId))
            ->when($search !== '', fn ($q) => ArabicSearch::apply(
                $q,
                ['name_ar', 'name_en', 'description_ar', 'description_en'],
                ArabicSearch::tokens($search),
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
            ->with('optionGroups.options')
            ->where('is_available', true)
            ->onMenu()
            ->orderByDesc('sold_count')
            ->limit(5)
            ->get();

        return ApiResponse::success(ProductResource::collection($products));
    }

    public function reviews(Request $request, Restaurant $restaurant): JsonResponse
    {
        $stars = array_map('intval', ListParams::of($request)->enum('stars', ['1', '2', '3', '4', '5']));

        $ratings = $restaurant->ratings()
            ->with('user:id,name')
            ->where('is_hidden', false)
            ->when($stars !== [], fn ($q) => $q->whereIn('stars', $stars))
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
