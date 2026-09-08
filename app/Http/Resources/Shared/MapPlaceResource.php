<?php

namespace App\Http\Resources\Shared;

use App\Support\Geo;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * doc 08 §2 — one row of `GET /geo/places`: a shop the map can label.
 *
 * Deliberately thin. This is not the browse card — it carries no rating, no
 * fee, no opening hours and no imagery, because the only thing drawn from it
 * is a pin, its tint and its name. Everything the customer taps through to is
 * `GET /restaurants/{id}`, which already exists.
 */
class MapPlaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);
        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;
        $category = $this->storeCategory;

        return [
            'id' => $this->id,
            'name' => $this->{"name_{$locale}"},
            'slug' => $this->slug,
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
            'distance_km' => ($lat !== null && $lng !== null)
                ? round(Geo::haversineKm((float) $this->latitude, (float) $this->longitude, $lat, $lng), 3)
                : null,
            // What tints the pin. A shop whose category the operator has not set
            // yet answers null and the client falls back to the brand colour —
            // a pin with no tint is still a pin with a name on it.
            'store_category' => $category === null ? null : [
                'slug' => $category->slug,
                'name' => $category->{"name_{$locale}"},
                'color' => $category->color,
                'icon' => $category->icon,
            ],
        ];
    }
}
