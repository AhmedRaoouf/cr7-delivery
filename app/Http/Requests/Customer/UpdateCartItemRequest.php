<?php

namespace App\Http\Requests\Customer;

use App\Support\BasketLimit;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Batch 67 — ownership before validation. Validation ran first, so another
        // customer's row answered 422 where a missing one answered 404 — an existence
        // oracle for somebody else's item (doc 12 §5: violations are a 404).
        abort_unless($this->route('item')?->cart?->user_id === $this->user()?->id, 404);

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
