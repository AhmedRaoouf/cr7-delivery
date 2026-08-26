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
            'address_id' => ['required', 'exists:user_addresses,id'],
            'promo_code' => ['nullable', 'string'],
            'payment_method' => ['required', 'in:cash,vodafone_cash,instapay'],
        ];
    }
}
