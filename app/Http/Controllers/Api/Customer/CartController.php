<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\AddCartItemAction;
use App\Actions\Customer\UpdateCartItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddCartItemRequest;
use App\Http\Requests\Customer\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Support\ApiResponse;
use App\Support\CartFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — cart is owned by the caller (doc 12 §5): cart.user_id = auth.id, always. */
class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cart = $request->user()->cart()->firstOrCreate([]);

        return ApiResponse::success(CartFormatter::format($cart, $request));
    }

    public function addItem(AddCartItemRequest $request, AddCartItemAction $action): JsonResponse
    {
        $cart = $action->execute($request->user(), $request->validated());

        return ApiResponse::success(CartFormatter::format($cart, $request), 'تمت الإضافة إلى السلة', status: 201);
    }

    public function updateItem(UpdateCartItemRequest $request, CartItem $item, UpdateCartItemAction $action): JsonResponse
    {
        $this->ensureOwned($request, $item);

        $action->execute($item, $request->validated());

        return ApiResponse::success(CartFormatter::format($item->cart, $request), 'تم تحديث السلة');
    }

    public function removeItem(Request $request, CartItem $item): JsonResponse
    {
        $this->ensureOwned($request, $item);

        $cart = $item->cart;
        $item->delete();

        return ApiResponse::success(CartFormatter::format($cart->fresh(), $request), 'تم حذف المنتج من السلة');
    }

    public function removeRestaurant(Request $request, int $restaurant): JsonResponse
    {
        $cart = $request->user()->cart()->firstOrCreate([]);

        $cart->items()->where('restaurant_id', $restaurant)->delete();

        return ApiResponse::success(CartFormatter::format($cart->fresh(), $request), 'تم حذف مطعم من السلة');
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $request->user()->cart()->firstOrCreate([]);

        $cart->items()->delete();

        return ApiResponse::success(CartFormatter::format($cart->fresh(), $request), 'تم تفريغ السلة');
    }

    public function validateCart(Request $request): JsonResponse
    {
        $cart = $request->user()->cart()->firstOrCreate([]);

        return ApiResponse::success(CartFormatter::format($cart, $request));
    }

    private function ensureOwned(Request $request, CartItem $item): void
    {
        abort_unless($item->cart->user_id === $request->user()->id, 404);
    }
}
