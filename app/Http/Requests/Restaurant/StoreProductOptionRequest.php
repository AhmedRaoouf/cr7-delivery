<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Concerns\ValidatesOptionGroups;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductOptionRequest extends FormRequest
{
    use ValidatesOptionGroups;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->optionRules($this->isMethod('post')) + [
            // doc 99 §1 — a word to the office beside a price change.
            'price_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return $this->optionMessages();
    }
}
