<?php

namespace App\Http\Resources\Shared;

use App\Models\StoreCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'image' => $this->image,
            'icon' => $this->icon,
            'color' => $this->color,
            // doc 99 §8 — D-176: how this category's places lay out their products.
            'product_layout' => StoreCategory::layoutOf($this->resource),
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
            // Absent unless the query asked for it — `whenCounted` keeps the public
            // endpoint from having to load a relation it does not use.
            'restaurants_count' => $this->whenCounted('restaurants'),
        ];
    }
}
