<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Not `exists:` — an id that is not one of the caller's addresses is
            // `address_not_found` whether or not the row exists (D-112, CheckoutAddress).
            'address_id' => ['required', 'integer'],
            'promo_code' => ['nullable', 'string'],
            'payment_method' => ['required', 'in:cash,vodafone_cash,instapay'],
        ];
    }
}
