<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * doc 08 §2 — GET /cities.
 *
 * Deliberately bilingual rather than localised: this route is read once and
 * cached for the session by clients that then have to render both languages
 * from the same rows, so it sends `name_ar` *and* `name_en` regardless of
 * `Accept-Language`.
 *
 * The reason it exists at all is `decimal:`. Returning the model raw serialised
 * every cast column as a **string** — `"center_lat": "31.3833000"` — and a
 * client reading it as a number crashed on the first row. Timestamps and
 * nothing else internal go over the wire either.
 */
class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'center_lat' => (float) $this->center_lat,
            'center_lng' => (float) $this->center_lng,
            'pricing_type' => $this->pricing_type,
            'default_min_order' => (float) $this->default_min_order,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
