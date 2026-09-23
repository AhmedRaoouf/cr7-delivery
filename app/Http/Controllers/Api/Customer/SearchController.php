<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductResource;
use App\Http\Resources\Customer\RestaurantResource;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use App\Support\ArabicSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — GET /search: unified restaurants + products. */
class SearchController extends Controller
{
    private const RESTAURANT_LIMIT = 10;

    private const PRODUCT_LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $search = (string) $request->string('search')->value();
        $tokens = ArabicSearch::tokens($search);

        if ($tokens === []) {
            return ApiResponse::success(['restaurants' => [], 'products' => []]);
        }

        return ApiResponse::success([
            'restaurants' => RestaurantResource::collection($this->restaurants($request, $tokens, $search)),
            'products' => ProductResource::collection($this->products($tokens, $search)),
        ]);
    }

    /** @param  list<string>  $tokens */
    private function restaurants(Request $request, array $tokens, string $search)
    {
        // `serviceZone` and `openingHours` are what `RestaurantResource` reads per card —
        // the fee, the ETA and «مفتوح الآن» — and each was a query per row (batch 68).
        $query = Restaurant::query()
            ->with(['serviceZone', 'openingHours'])
            ->where('is_active', true)
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId));

        // The cuisine tags are searched too, so «برجر» reaches a burger place whose name
        // never says the word.
        ArabicSearch::apply($query, ['name_ar', 'name_en', 'description_ar', 'description_en'], $tokens);

        return $query
            ->orderBy(ArabicSearch::rank('name_ar', $search))
            ->orderByDesc('rating_avg')
            ->limit(self::RESTAURANT_LIMIT)
            ->get();
    }

    /** @param  list<string>  $tokens */
    private function products(array $tokens, string $search)
    {
        $query = Product::query()
            ->where('is_available', true)
            ->onMenu()
            ->with(['restaurant:id,name_ar,name_en,is_active', 'optionGroups.options'])
            ->whereHas('restaurant', fn ($q) => $q->where('is_active', true));

        ArabicSearch::apply($query, ['name_ar', 'name_en', 'description_ar', 'description_en'], $tokens);

        return $query
            ->orderBy(ArabicSearch::rank('name_ar', $search))
            ->orderByDesc('rating_avg')
            ->limit(self::PRODUCT_LIMIT)
            ->get();
    }
}
