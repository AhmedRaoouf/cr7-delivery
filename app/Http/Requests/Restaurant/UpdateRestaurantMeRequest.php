<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'cover' => ['nullable', 'image', 'max:5120'],
            'phone' => ['sometimes', 'required', 'string'],
            'whatsapp' => ['nullable', 'string'],
        ];
    }
}
