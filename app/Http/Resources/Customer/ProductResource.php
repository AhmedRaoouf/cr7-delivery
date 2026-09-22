<?php

namespace App\Http\Resources\Customer;

use App\Support\Catalog\OptionSelection;
use App\Support\Locale;
use App\Support\Media\MediaLibrary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §4 — GET /restaurants/{id}/products item shape. */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);

        return [
            'id' => $this->id,
            'name' => $this->{"name_{$locale}"},
            'description' => $this->{"description_{$locale}"},
            'image' => MediaLibrary::orPlaceholder($this->image),
            'price' => (float) $this->price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'is_available' => $this->is_available,
            'is_featured' => $this->is_featured,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'tags' => $this->tags,
            'menu_category_id' => $this->menu_category_id,
            // CUS-11 search returns dishes across restaurants, so a row has to name
            // the restaurant it belongs to or it cannot be opened or added.
            'restaurant_id' => $this->restaurant_id,
            'restaurant_name' => $this->whenLoaded('restaurant', fn () => $this->restaurant->{"name_{$locale}"}),
            // FR-MENU-08 — «+» on a list row may not add a dish that still has a
            // choice to make; it has to open `CUS-15` instead. The app decided
            // that by looking at `option_groups`, which **only the detail
            // resource sends** — so on a list row the array was always empty, the
            // question always answered «no», and a quick-add of a dish with a
            // required size went to the server without one. A whole array per row
            // to answer one boolean is the wrong trade on a search that returns
            // dishes from every restaurant, so the boolean is what travels.
            'requires_configuration' => $this->requiresConfiguration(),
            // D-166 — a quick add still posts a dish whose options are all optional;
            // `from_price` is what the card prints («من …») when a required size moves
            // the price, so a dish priced only through its sizes never lists at 0.
            'has_options' => $this->hasOptions(),
            'from_price' => OptionSelection::fromPrice($this->resource),
        ];
    }
}
