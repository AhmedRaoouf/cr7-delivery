<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class StoreRestaurantProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'menu_category_id' => [$required, 'exists:menu_categories,id'],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:5120'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['image', 'max:5120'],
            'price' => [$required, 'numeric', 'min:0'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'prep_minutes' => ['nullable', 'integer', 'min:1'],
            'calories' => ['nullable', 'integer', 'min:0'],
            'ingredients_ar' => ['nullable', 'string'],
            'ingredients_en' => ['nullable', 'string'],
            'allergens' => ['nullable', 'array'],
            'spice_level' => ['nullable', 'integer', 'between:0,3'],
            'serving_size_ar' => ['nullable', 'string'],
            'serving_size_en' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'is_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'remove_image' => ['nullable', 'boolean'],
            'remove_gallery' => ['nullable', 'boolean'],
        ];
    }
}
