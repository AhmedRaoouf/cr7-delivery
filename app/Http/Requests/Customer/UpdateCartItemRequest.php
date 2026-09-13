<?php

namespace App\Http\Requests\Customer;

use App\Support\BasketLimit;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // D-114 — `cart.max_qty_per_line` on the panel; it was a hard-coded 50.
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.BasketLimit::maxQuantityPerLine()],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'option_ids' => ['sometimes', 'array'],
            'option_ids.*' => ['integer', 'exists:product_options,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity.max' => BasketLimit::quantitySentence(),
        ];
    }
}
