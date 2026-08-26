<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductResource;
use App\Http\Resources\Customer\RestaurantResource;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — GET/POST/DELETE /favorites {type: restaurant|product, id}. */
class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favorites = $request->user()->favorites()->with('favoritable')->latest()->get();

        $data = $favorites->map(function (Favorite $favorite) use ($request) {
            $type = $favorite->favoritable_type;

            return [
                'type' => $type,
                'id' => $favorite->favoritable_id,
                'item' => match (true) {
                    $favorite->favoritable instanceof Restaurant => new RestaurantResource($favorite->favoritable),
                    $favorite->favoritable instanceof Product => new ProductResource($favorite->favoritable),
                    default => null,
                },
            ];
        })->filter(fn ($f) => $f['item'] !== null)->values();

        return ApiResponse::success($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:restaurant,product'],
            'id' => ['required', 'integer'],
        ]);

        $model = $validated['type'] === 'restaurant' ? Restaurant::class : Product::class;
        $model::query()->findOrFail($validated['id']);

        $favorite = $request->user()->favorites()->firstOrCreate([
            'favoritable_type' => $validated['type'],
            'favoritable_id' => $validated['id'],
        ]);

        return ApiResponse::success($favorite, 'تمت الإضافة إلى المفضلة', status: 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:restaurant,product'],
            'id' => ['required', 'integer'],
        ]);

        $request->user()->favorites()
            ->where('favoritable_type', $validated['type'])
            ->where('favoritable_id', $validated['id'])
            ->delete();

        return ApiResponse::success(message: 'تمت الإزالة من المفضلة');
    }
}
