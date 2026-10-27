<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\PromoType;
use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use App\Support\ApiResponse;
use App\Support\CheckoutPricer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 — POST /promo/validate {code, address_id}. */
class PromoController extends Controller
{
    public function validate(Request $request, CheckoutPricer $pricer): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'address_id' => ['required', 'exists:user_addresses,id'],
        ]);

        $user = $request->user();
        $address = UserAddress::query()->where('user_id', $user->id)->findOrFail($validated['address_id']);
        $cart = $user->cart()->firstOrCreate([]);

        $promo = $pricer->validatePromo($user, $cart, $address, $validated['code']);
        $subtotals = $pricer->restaurantSubtotals($cart);
        $discount = CheckoutPricer::discountOn($promo, $subtotals);

        // doc 96 — a place's own code in a basket that also holds other places. The app
        // (every build so far) applies a percentage to the whole basket it shows, so the
        // amount is handed over as what it really is — a fixed sum off — and the basket
        // reads the number checkout will charge. `/checkout/quote` stays the authority.
        $isPartial = $promo->type->value !== 'free_delivery'
            && count(CheckoutPricer::discountedIds($promo, $subtotals)) < $subtotals->count();

        return ApiResponse::success([
            'code' => $promo->code,
            'title' => $promo->title_ar,
            'type' => $isPartial ? PromoType::Fixed : $promo->type,
            'value' => $isPartial ? $discount : (float) $promo->value,
            'max_discount' => $isPartial || $promo->max_discount === null ? null : (float) $promo->max_discount,
            'discount' => $discount,
            'restaurant_ids' => $promo->restaurantIds(),
            'valid' => true,
        ]);
    }
}
