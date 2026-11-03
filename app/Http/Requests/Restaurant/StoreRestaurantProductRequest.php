<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Concerns\FallsBackToArabic;
use App\Http\Requests\Concerns\ValidatesProductGallery;
use App\Models\Product;
use App\Support\Catalog\PriceApproval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRestaurantProductRequest extends FormRequest
{
    use FallsBackToArabic, ValidatesProductGallery;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->fillEnglishFromArabic($this, ['name_en' => 'name_ar'], $this->isMethod('post'));
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            // Batch 67 — the category must be this place's own. `exists:menu_categories,id`
            // accepted any place's category, so a dish could be filed into a rival's menu.
            'menu_category_id' => [$required, Rule::exists('menu_categories', 'id')->where('restaurant_id', (int) $this->user()?->restaurant?->id)->whereNull('deleted_at')],
            // Doc 82 — the dish's cuisine (the customer's «برجر / بيتزا» filter). Optional
            // on the wire so an older client still saves; the model files a dish that
            // names none by its name, or under «أخرى».
            'cuisine_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cuisines', 'id')->where('is_active', true)],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            ...$this->galleryRules(),
            'price' => [$required, 'numeric', 'min:0'],
            // A new dish compares the two fields it was sent. An edit may send only one of
            // them, so it is compared in `after()` against the other's real value (the live
            // row, or the price already waiting for the office) — `lt:price` against an
            // absent `price` refused every discount-only edit, and let a price lowered under
            // the live discount through.
            'discount_price' => $this->isMethod('post')
                ? ['nullable', 'numeric', 'min:0', 'lt:price']
                : ['nullable', 'numeric', 'min:0'],
            // doc 99 §1 — a word to the office beside a price change.
            'price_note' => ['nullable', 'string', 'max:500'],
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

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $product = $this->route('product');

            if ($this->isMethod('post') || ! $product instanceof Product || $validator->errors()->isNotEmpty()) {
                return;
            }

            // Another place's dish is the controller's 404, never a hint about its price.
            if ((int) $product->restaurant_id !== (int) $this->user()?->restaurant?->id) {
                return;
            }

            if (! $this->has('price') && ! $this->has('discount_price')) {
                return;
            }

            [$price, $discount] = PriceApproval::effectivePair($product, $this->only(['price', 'discount_price']));

            if ($discount !== null && $discount >= $price) {
                $validator->errors()->add('discount_price', __('سعر الخصم يجب أن يكون أقل من سعر الصنف'));
            }
        }];
    }
}
