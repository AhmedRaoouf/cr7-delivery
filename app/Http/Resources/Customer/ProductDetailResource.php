<?php

namespace App\Http\Resources\Customer;

use App\Support\Catalog\LinePrice;
use App\Support\Catalog\OptionSelection;
use App\Support\Locale;
use App\Support\Media\MediaLibrary;
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
            'image' => MediaLibrary::orPlaceholder($this->image),
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
            'requires_configuration' => $this->requiresConfiguration(),
            'has_options' => $this->hasOptions(),
            'from_price' => OptionSelection::fromPrice($this->resource),
            // D-164 — a group with no options is a draft and is not the customer's to
            // see; the rest arrive in the place's order, with the limits the basket
            // will hold them to (a single group always takes exactly one at most).
            'option_groups' => $this->whenLoaded('optionGroups', fn () => OptionSelection::liveGroups($this->resource)->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->{"name_{$locale}"},
                'selection_type' => $group->selection_type,
                // doc 78 — `final`: the chosen option's `price` is the price of one unit.
                'price_mode' => $group->price_mode,
                'is_required' => $group->is_required,
                'min_select' => OptionSelection::minimum($group),
                'max_select' => OptionSelection::maximum($group),
                'options' => $group->options->map(fn ($option) => [
                    'id' => $option->id,
                    'name' => $option->{"name_{$locale}"},
                    // doc 78 — `price` is the number to print beside the option: the whole
                    // price in a `final` group, the extra in an `add` one. `price_delta` is
                    // what choosing it changes one unit by against the dish's price (negative
                    // for a size cheaper than the dish) — the sum a 1.0.16 app does.
                    'price' => LinePrice::shownPrice($option, $group, LinePrice::effective($this->resource)),
                    'price_delta' => LinePrice::sheetDelta($option, $group, LinePrice::effective($this->resource)),
                    'is_default' => $option->is_default,
                    'is_available' => $option->is_available,
                ]),
            ])),
        ];
    }
}
