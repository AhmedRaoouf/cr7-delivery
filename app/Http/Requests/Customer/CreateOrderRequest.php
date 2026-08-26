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
            'address_id' => ['required', 'exists:user_addresses,id'],
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
