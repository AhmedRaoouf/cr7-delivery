<?php

namespace App\Http\Resources\Customer;

use App\Support\Locale;
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
            'image' => $this->image,
            'price' => (float) $this->price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'is_available' => $this->is_available,
            'is_featured' => $this->is_featured,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'tags' => $this->tags,
            'menu_category_id' => $this->menu_category_id,
        ];
    }
}
