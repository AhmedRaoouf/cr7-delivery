<?php

namespace App\Actions\Errand;

use App\Exceptions\ApiException;
use App\Models\Setting;
use App\Support\Geo;
use App\Support\ZoneLocator;

/**
 * What an errand ought to cost — a suggestion, never a price.
 *
 * The customer names the fee (operator's ruling, 2026-09-07: «هو بيحدد السعر… واللي يقبل
 * السعر المناسب ليه»), so this exists only so the number they type starts from something
 * real. A blank fee field on a screen nobody has used before gets 5 or 500 with equal
 * probability, and both waste everybody's time.
 *
 * Both ends must be inside coverage. That is the one hard rule here: an errand is a
 * captain riding somewhere, and a captain cannot ride to a town this platform does not
 * serve.
 */
class QuoteErrandAction
{
    /** @return array{distance_km: float, suggested_fee: float, min_fee: float, city_id: ?int, service_zone_id: ?int, pickup_zone: ?string, dropoff_zone: ?string} */
    public function execute(float $pickupLat, float $pickupLng, float $dropoffLat, float $dropoffLng): array
    {
        $pickupZone = ZoneLocator::forPoint($pickupLat, $pickupLng);
        $pickupCity = $pickupZone?->city ?? ZoneLocator::cityForPoint($pickupLat, $pickupLng);

        if ($pickupCity === null) {
            throw new ApiException('pickup_outside_coverage', 'موقع الاستلام خارج نطاق الخدمة', 422);
        }

        $dropoffZone = ZoneLocator::forPoint($dropoffLat, $dropoffLng);
        $dropoffCity = $dropoffZone?->city ?? ZoneLocator::cityForPoint($dropoffLat, $dropoffLng);

        if ($dropoffCity === null) {
            throw new ApiException('dropoff_outside_coverage', 'موقع التسليم خارج نطاق الخدمة', 422);
        }

        $distanceKm = Geo::haversineKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);

        $base = (float) self::setting('errands.base_fee', 20);
        $perKm = (float) self::setting('errands.per_km_fee', 5);
        $minimum = (float) self::setting('errands.min_fee', 20);

        $suggested = max($minimum, round($base + ($perKm * $distanceKm), 0));

        return [
            'distance_km' => round($distanceKm, 3),
            'suggested_fee' => $suggested,
            'min_fee' => $minimum,
            'city_id' => $pickupCity->id,
            'service_zone_id' => $pickupZone?->id,
            'pickup_zone' => $pickupZone?->name_ar ?? $pickupCity->name_ar,
            'dropoff_zone' => $dropoffZone?->name_ar ?? $dropoffCity->name_ar,
        ];
    }

    private static function setting(string $key, float|int $fallback): float
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return is_numeric($value) ? (float) $value : (float) $fallback;
    }
}
