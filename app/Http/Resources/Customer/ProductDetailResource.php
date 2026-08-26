<?php

namespace App\Http\Resources\Customer;

use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §4 — GET /products/{id}: every attribute CUS-15 renders (FR-MENU-08). */
class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'name' => $this->{"name_{$locale}"},
            'description' => $this->{"description_{$locale}"},
            'image' => $this->image,
            'gallery' => $this->gallery,
            'price' => (float) $this->price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'is_available' => $this->is_available,
            'prep_minutes' => $this->prep_minutes,
            'calories' => $this->calories,
            'serving_size' => $this->{"serving_size_{$locale}"},
            'spice_level' => $this->spice_level,
            'ingredients' => $this->{"ingredients_{$locale}"},
            'allergens' => $this->allergens,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'tags' => $this->tags,
            'option_groups' => $this->whenLoaded('optionGroups', fn () => $this->optionGroups->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->{"name_{$locale}"},
                'selection_type' => $group->selection_type,
                'is_required' => $group->is_required,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                'options' => $group->options->map(fn ($option) => [
                    'id' => $option->id,
                    'name' => $option->{"name_{$locale}"},
                    'price_delta' => (float) $option->price_delta,
                    'is_default' => $option->is_default,
                    'is_available' => $option->is_available,
                ]),
            ])),
        ];
    }
}
