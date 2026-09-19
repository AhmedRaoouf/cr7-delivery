<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Concerns\ValidatesProductGallery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantProductRequest extends FormRequest
{
    use ValidatesProductGallery;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            // Batch 67 — the category must be this place's own. `exists:menu_categories,id`
            // accepted any place's category, so a dish could be filed into a rival's menu.
            'menu_category_id' => [$required, Rule::exists('menu_categories', 'id')->where('restaurant_id', (int) $this->user()?->restaurant?->id)],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            ...$this->galleryRules(),
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
