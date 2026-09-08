<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /cuisines`, and the row is deliberately **not** localised.
 *
 * Both names travel, like `StoreCategoryResource` and every other raw catalogue row
 * (CLAUDE.md §6): the app caches the list once and reads it under whichever locale is
 * on screen at the time, so narrowing it here would strand a cached Arabic list in an
 * English session.
 *
 * `icon` is a slug the client resolves to a bundled mark; `image` is the artwork the
 * chip is drawn on and is null until the operator uploads one.
 */
class CuisineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'icon' => $this->icon,
            'image' => $this->image,
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'restaurants_count' => $this->whenCounted('restaurants'),
        ];
    }
}
