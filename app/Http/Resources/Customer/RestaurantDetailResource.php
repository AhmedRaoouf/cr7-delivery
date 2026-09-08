<?php

namespace App\Http\Resources\Customer;

use App\Support\Geo;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §4 — GET /restaurants/{id}: full profile + opening hours + computed delivery fee & ETA. */
class RestaurantDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);
        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;

        $zone = $this->serviceZone;

        return [
            'id' => $this->id,
            'name' => $this->{"name_{$locale}"},
            'description' => $this->{"description_{$locale}"},
            'slug' => $this->slug,
            'logo' => $this->logo,
            'cover' => $this->cover,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'address' => $this->address,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'cuisines' => $this->whenLoaded('cuisines', fn () => $this->cuisines->pluck("name_{$locale}")),
            // «مطعم» · «صيدلية» · «كافيه» — it replaced the delivery fee on the
            // card and in the header strip, so the detail screen has to carry it
            // too or the header changes the moment the fetch lands.
            'store_category' => $this->whenLoaded('storeCategory', fn () => $this->storeCategory === null ? null : [
                'id' => $this->storeCategory->id,
                'slug' => $this->storeCategory->slug,
                'name' => $this->storeCategory->{"name_{$locale}"},
                'icon' => $this->storeCategory->icon,
            ]),
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'delivery_fee' => $zone?->delivery_fee !== null ? (float) $zone->delivery_fee : null,
            'free_delivery_over' => $zone?->free_delivery_over !== null ? (float) $zone->free_delivery_over : null,
            // The shop's own window when it publishes one, the zone's estimate
            // otherwise — see `Restaurant::etaWindow()`.
            'delivery_time_min' => $this->etaWindow()[0],
            'delivery_time_max' => $this->etaWindow()[1],
            'min_order_value' => (float) $this->min_order_value,
            'distance_km' => ($lat && $lng) ? round(Geo::haversineKm((float) $this->latitude, (float) $this->longitude, $lat, $lng), 2) : null,
            'is_open_now' => $this->isOpenNow(),
            'is_24_7' => (bool) $this->is_24_7,
            'menu_layout' => $this->menu_layout,
            'price_band' => $this->price_band,
            'tagline' => $this->{"tagline_{$locale}"},
            'brand_color' => $this->brand_color,
            // D-58 — the printed sheets the restaurant handed us, shown whole in their
            // own section behind pill 0 («المنيو»). Never cropped (doc 09 §imagery).
            'menu_sheets' => $this->menu_sheets ?? [],
            'opening_hours' => $this->whenLoaded('openingHours', fn () => $this->openingHours->map(fn ($h) => [
                'weekday' => $h->weekday,
                'opens_at' => $h->opens_at,
                'closes_at' => $h->closes_at,
                'is_closed' => $h->is_closed,
                'is_24h' => $h->is_24h,
            ])),
        ];
    }
}
