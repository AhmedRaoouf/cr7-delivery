<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Resources\Customer\ProductResource;
use App\Http\Resources\Customer\RestaurantResource;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — GET /search: unified restaurants + products. */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->value();

        if (! $search) {
            return ApiResponse::success(['restaurants' => [], 'products' => []]);
        }

        $restaurants = Restaurant::query()
            ->where('is_active', true)
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->whereFullText(['name_ar', 'name_en'], $search)
            ->limit(10)
            ->get();

        $products = Product::query()
            ->where('is_available', true)
            ->whereFullText(['name_ar', 'name_en'], $search)
            ->with('restaurant:id,name_ar,name_en,is_active')
            ->whereHas('restaurant', fn ($q) => $q->where('is_active', true))
            ->limit(20)
            ->get();

        return ApiResponse::success([
            'restaurants' => RestaurantResource::collection($restaurants),
            'products' => ProductResource::collection($products),
        ]);
    }
}
