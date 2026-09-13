<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleOrderRequest extends FormRequest
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
            // The window itself is enforced in `ScheduleOrderAction`, where the message
            // can say how far out is too soon in words the customer reads.
            'scheduled_for' => ['required', 'date'],
            'promo_code' => ['nullable', 'string'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
        ];
    }
}
