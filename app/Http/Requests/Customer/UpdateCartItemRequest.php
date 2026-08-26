<?php

namespace App\Http\Requests\Customer;

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
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'option_ids' => ['sometimes', 'array'],
            'option_ids.*' => ['integer', 'exists:product_options,id'],
        ];
    }
}
