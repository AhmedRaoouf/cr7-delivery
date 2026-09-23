<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesProductGallery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    use ValidatesProductGallery;

    public function authorize(): bool
    {
        return $this->user()->can('products.create');
    }

    public function rules(): array
    {
        return [
            // Batch 67 — a category of the restaurant in the path, not of any restaurant.
            'menu_category_id' => ['required', Rule::exists('menu_categories', 'id')->where('restaurant_id', (int) $this->route('restaurant')?->getKey())->whereNull('deleted_at')],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            ...$this->galleryRules(),
            'price' => ['required', 'numeric', 'min:0'],
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
