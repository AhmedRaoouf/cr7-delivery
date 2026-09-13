<?php

namespace App\Http\Requests\Customer;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
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
            'payment_method' => ['required', 'in:cash,vodafone_cash,instapay'],
            'promo_code' => ['nullable', 'string'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function idempotencyKey(): string
    {
        $key = $this->header('Idempotency-Key');

        if (! $key) {
            throw new ApiException('validation_failed', 'Idempotency-Key header مطلوب', 422);
        }

        return $key;
    }
}
