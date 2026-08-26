<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class AcceptOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prep_minutes' => ['required', 'integer', 'between:5,120'],
            'restaurant_order_ref' => ['nullable', 'string', 'max:32'],
        ];
    }
}
