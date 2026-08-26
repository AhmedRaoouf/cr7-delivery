<?php

namespace App\Http\Controllers\Api\Customer;

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

        return ApiResponse::success([
            'code' => $promo->code,
            'title' => $promo->title_ar,
            'type' => $promo->type,
            'value' => (float) $promo->value,
            'max_discount' => $promo->max_discount !== null ? (float) $promo->max_discount : null,
            'valid' => true,
        ]);
    }
}
