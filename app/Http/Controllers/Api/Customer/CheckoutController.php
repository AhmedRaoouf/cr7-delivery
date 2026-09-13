<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CheckoutQuoteRequest;
use App\Support\ApiResponse;
use App\Support\CheckoutAddress;
use App\Support\CheckoutPricer;
use Illuminate\Http\JsonResponse;

/** doc 08 §4 — POST /checkout/quote: fees, discount, ETA, totals. No side effects. */
class CheckoutController extends Controller
{
    public function quote(CheckoutQuoteRequest $request, CheckoutPricer $pricer): JsonResponse
    {
        $user = $request->user();
        $address = CheckoutAddress::find($user, $request->input('address_id'));
        $cart = $user->cart()->firstOrCreate([]);

        $quote = $pricer->price($user, $cart, $address, $request->string('promo_code')->value() ?: null, $request->string('payment_method')->value());
        unset($quote['promo_code_id']);

        return ApiResponse::success($quote);
    }
}
