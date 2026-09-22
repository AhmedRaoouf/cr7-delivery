<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesOptionGroups;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductOptionRequest extends FormRequest
{
    use ValidatesOptionGroups;

    public function authorize(): bool
    {
        return $this->user()->can('products.update');
    }

    public function rules(): array
    {
        return $this->optionRules(true);
    }

    public function messages(): array
    {
        return $this->optionMessages();
    }
}
