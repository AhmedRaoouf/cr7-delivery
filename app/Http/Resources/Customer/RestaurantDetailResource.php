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
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'delivery_fee' => $zone?->delivery_fee !== null ? (float) $zone->delivery_fee : null,
            'free_delivery_over' => $zone?->free_delivery_over !== null ? (float) $zone->free_delivery_over : null,
            'delivery_time_min' => $zone ? max(5, $zone->avg_delivery_minutes - 10) : null,
            'delivery_time_max' => $zone ? $zone->avg_delivery_minutes + 10 : null,
            'min_order_value' => (float) $this->min_order_value,
            'distance_km' => ($lat && $lng) ? round(Geo::haversineKm((float) $this->latitude, (float) $this->longitude, $lat, $lng), 2) : null,
            'is_open_now' => $this->isOpenNow(),
            'menu_layout' => $this->menu_layout,
            'opening_hours' => $this->whenLoaded('openingHours', fn () => $this->openingHours->map(fn ($h) => [
                'weekday' => $h->weekday,
                'opens_at' => $h->opens_at,
                'closes_at' => $h->closes_at,
                'is_closed' => $h->is_closed,
            ])),
        ];
    }
}
