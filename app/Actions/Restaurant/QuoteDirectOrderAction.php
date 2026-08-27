<?php

namespace App\Actions\Restaurant;

use App\Exceptions\ApiException;
use App\Models\DeliveryPricingRule;
use App\Models\Restaurant;
use App\Support\Geo;

/** doc 08 §6.2 — POST /restaurant/direct-orders/quote: coverage, zone, distance, fee, ETA. Never creates anything. */
class QuoteDirectOrderAction
{
    public function execute(Restaurant $restaurant, float $lat, float $lng): array
    {
        $servedZoneIds = $restaurant->servedZones()->pluck('service_zones.id')->push($restaurant->service_zone_id)->unique();

        $zone = \App\Models\ServiceZone::query()->whereIn('id', $servedZoneIds)->where('is_active', true)->get()
            ->first(function ($zone) use ($lat, $lng) {
                if ($zone->polygon) {
                    return Geo::pointInPolygon($lat, $lng, $zone->polygon[0] ?? $zone->polygon);
                }

                return $zone->radius_km && Geo::haversineKm((float) $zone->center_lat, (float) $zone->center_lng, $lat, $lng) <= (float) $zone->radius_km;
            });

        if (! $zone) {
            $nearest = \App\Models\ServiceZone::query()->whereIn('id', $servedZoneIds)->first();

            throw new ApiException('address_outside_coverage', 'هذا العنوان خارج نطاق التغطية', 422, meta: [
                'nearest_zone' => $nearest?->only(['id', 'name_ar', 'name_en']),
            ]);
        }

        $distanceKm = Geo::haversineKm((float) $restaurant->latitude, (float) $restaurant->longitude, $lat, $lng);

        $rule = DeliveryPricingRule::query()
            ->where('city_id', $restaurant->city_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('service_zone_id')->orWhere('service_zone_id', $zone->id))
            ->where('min_distance_km', '<=', $distanceKm)
            ->where('max_distance_km', '>=', $distanceKm)
            ->orderByDesc('service_zone_id')
            ->first();

        $fee = (float) ($rule->fee ?? $zone->delivery_fee ?? 0);

        return [
            'covered' => true,
            'service_zone_id' => $zone->id,
            'service_zone_name' => $zone->name_ar,
            'distance_km' => round($distanceKm, 2),
            'delivery_fee' => $fee,
            'eta_minutes' => $zone->avg_delivery_minutes,
        ];
    }
}
