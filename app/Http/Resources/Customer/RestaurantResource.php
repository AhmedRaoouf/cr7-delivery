<?php

namespace App\Http\Resources\Customer;

use App\Models\StoreCategory;
use App\Support\Catalog\RatingBadge;
use App\Support\Geo;
use App\Support\Locale;
use App\Support\Media\MediaLibrary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §4 — GET /restaurants item shape. */
class RestaurantResource extends JsonResource
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
            'slug' => $this->slug,
            'logo' => $this->logo,
            'cover' => MediaLibrary::orPlaceholder($this->cover),
            'cuisines' => $this->whenLoaded('cuisines', fn () => $this->cuisines->pluck("name_{$locale}")),
            'store_category' => $this->whenLoaded('storeCategory', fn () => $this->storeCategory === null ? null : [
                'id' => $this->storeCategory->id,
                'slug' => $this->storeCategory->slug,
                'name' => $this->storeCategory->{"name_{$locale}"},
                'icon' => $this->storeCategory->icon,
            ]),
            // doc 99 §5 — the true average; an app before 1.0.24 is sent the welcome 5.0
            // here (its only field), and `PublicCache` keeps that body apart.
            'rating_avg' => RatingBadge::ratingAvgFor($this->resource, $request),
            'rating_count' => $this->rating_count,
            // doc 99 §5–§6 — what the customer sees beside the name.
            'rating_badge' => RatingBadge::for($this->resource),
            // doc 99 §8 — D-176: rows (`list`) or the two-column shelf (`grid`).
            'product_layout' => StoreCategory::layoutOf($this->storeCategory),
            'delivery_fee' => $zone?->delivery_fee !== null ? (float) $zone->delivery_fee : null,
            // doc 97 — added to the customer's area fee when this place is in the basket.
            'delivery_surcharge' => (float) $this->delivery_surcharge,
            'free_delivery_over' => $zone?->free_delivery_over !== null ? (float) $zone->free_delivery_over : null,
            // The shop's own window when it publishes one, the zone's estimate
            // otherwise — see `Restaurant::etaWindow()`.
            'delivery_time_min' => $this->etaWindow()[0],
            'delivery_time_max' => $this->etaWindow()[1],
            'min_order_value' => (float) $this->min_order_value,
            'distance_km' => ($lat && $lng) ? round(Geo::haversineKm((float) $this->latitude, (float) $this->longitude, $lat, $lng), 2) : null,
            'price_band' => $this->price_band,
            'tagline' => $this->{"tagline_{$locale}"},
            'brand_color' => $this->brand_color,
            'is_open_now' => $this->isOpenNow(),
            'is_24_7' => (bool) $this->is_24_7,
            'next_open_at' => null,
            'is_busy' => false,
            'has_offers' => false,
        ];
    }
}
