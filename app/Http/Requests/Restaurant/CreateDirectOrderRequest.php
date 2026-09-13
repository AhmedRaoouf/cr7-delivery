<?php

namespace App\Http\Requests\Restaurant;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;

class CreateDirectOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guest_customer_name' => ['required', 'string', 'max:255'],
            'guest_customer_phone' => ['required', 'string'],
            'guest_customer_phone_alt' => ['nullable', 'string'],
            'delivery_address_text' => ['required', 'string', 'max:500'],
            'delivery_latitude' => ['required', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['required', 'numeric', 'between:-180,180'],
            // D-102 — the customer's area; its price is the fee.
            'zone_id' => ['nullable', 'integer'],
            'restaurant_order_ref' => ['required', 'string', 'max:32'],
            'declared_items_text' => ['nullable', 'string', 'max:1000'],
            'declared_order_value' => ['nullable', 'numeric', 'min:0'],
            'food_payment' => ['required', 'in:paid_at_restaurant,collect_by_driver'],
            'ready_now' => ['required', 'boolean'],
            'prep_minutes' => ['required_if:ready_now,false', 'nullable', 'integer', 'min:1', 'max:120'],
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
