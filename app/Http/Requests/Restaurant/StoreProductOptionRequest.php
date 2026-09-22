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
        return $this->optionRules($this->isMethod('post'));
    }

    public function messages(): array
    {
        return $this->optionMessages();
    }
}
