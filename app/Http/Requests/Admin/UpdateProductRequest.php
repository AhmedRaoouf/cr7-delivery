<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.update');
    }

    public function rules(): array
    {
        return [
            // Batch 67 — a category of the restaurant in the path, not of any restaurant.
            'menu_category_id' => ['sometimes', 'required', Rule::exists('menu_categories', 'id')->where('restaurant_id', (int) $this->route('restaurant')?->getKey())],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
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
