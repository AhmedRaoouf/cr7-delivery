<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductResource;
use App\Http\Resources\Customer\RestaurantResource;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — GET/POST/DELETE /favorites {type: restaurant|product, id}. */
class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // A favourite place is drawn with its fee, ETA and «مفتوح الآن», which read its zone
        // and today's hours — loaded once for the list, not once per card (batch 68).
        $favorites = $request->user()->favorites()
            ->with(['favoritable' => fn (MorphTo $morph) => $morph->morphWith([
                Restaurant::class => ['serviceZone', 'openingHours'],
            ])])
            ->latest()
            ->get();

        $data = $favorites->map(function (Favorite $favorite) {
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

    /**
     * Adopt the favourites a guest collected on the device.
     *
     * Operator, 2026-09-07: «لما في حاجة تتحط في الفيفوريتس فاحفظها لوك عادي،
     * وساعات ما يخش يسجل بحساب تبعتها للباك في الحساب». So the app keeps a
     * guest's hearts in its own prefs and hands the list over once, on the first
     * sign-in — the customer does not lose the shortlist they built while
     * browsing.
     *
     * **Merge, never replace.** Someone signing in on a second phone would
     * otherwise wipe what the account already holds. `firstOrCreate` per row,
     * unknown ids skipped rather than 404-ing the whole call: the device's list
     * can outlive a product the operator deleted, and a request that fails
     * wholesale would strand the rest of it forever.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurants' => ['nullable', 'array', 'max:500'],
            'restaurants.*' => ['integer'],
            'products' => ['nullable', 'array', 'max:500'],
            'products.*' => ['integer'],
        ]);

        $adopted = 0;

        foreach ([
            'restaurant' => ['ids' => $validated['restaurants'] ?? [], 'model' => Restaurant::class],
            'product' => ['ids' => $validated['products'] ?? [], 'model' => Product::class],
        ] as $type => $spec) {
            $existing = $spec['model']::query()
                ->whereIn('id', array_unique($spec['ids']))
                ->pluck('id');

            foreach ($existing as $id) {
                $favorite = $request->user()->favorites()->firstOrCreate([
                    'favoritable_type' => $type,
                    'favoritable_id' => $id,
                ]);

                if ($favorite->wasRecentlyCreated) {
                    $adopted++;
                }
            }
        }

        return ApiResponse::success(
            ['adopted' => $adopted],
            'تمت مزامنة المفضلة',
        );
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
