<?php

namespace App\Actions\Restaurant;

use App\Exceptions\ApiException;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\Setting;
use App\Support\Geo;
use App\Support\ZoneLocator;

/**
 * doc 08 §6.2 — POST /restaurant/direct-orders/quote: coverage, zone, distance, fee, ETA.
 * Never creates anything.
 *
 * D-102 (2026-09-13) — «ايا كان ال delivery فين والمطعم فين تمن التوصيل بيتحسب علي مكان
 * العميل». The fee is the price of the **customer's area**, exactly as a customer's own
 * basket is priced. This used to match the drop pin against the zones the place itself
 * serves — so a customer one area over matched nothing and the place was told the
 * address was outside coverage — and then priced it by the distance from the place.
 * Now the place picks the area from the same list a customer picks from (`zone_id`);
 * without one, the pin is placed in the most specific priced area that contains it.
 */
class QuoteDirectOrderAction
{
    public function execute(Restaurant $restaurant, float $lat, float $lng, ?int $zoneId = null): array
    {
        $zone = $zoneId !== null
            ? ServiceZone::query()->where('is_active', true)->find($zoneId)
            : (ZoneLocator::suggestFor($lat, $lng) ?? ZoneLocator::forPoint($lat, $lng));

        if (! $zone) {
            throw new ApiException(
                $zoneId !== null ? 'zone_not_found' : 'address_outside_coverage',
                $zoneId !== null ? 'اختر منطقة العميل من القائمة' : 'حدّد منطقة العميل من القائمة — هذا الموقع خارج المناطق المسعّرة',
                422,
                errors: ['zone_id' => [$zoneId !== null ? 'اختر منطقة العميل من القائمة' : 'حدّد منطقة العميل']],
            );
        }

        $distanceKm = Geo::haversineKm((float) $restaurant->latitude, (float) $restaurant->longitude, $lat, $lng);

        // The area's price, and nothing else — no distance band, no place location.
        $fee = round((float) ($zone->delivery_fee ?? 0), 2);

        // D-64 — a direct order is a delivery the platform sold, so it carries the
        // service fee too. `platform.order_fee_applies_to_direct` exempts phone orders
        // without a deploy if the operator ever decides otherwise.
        $appliesToDirect = (bool) (Setting::query()
            ->where('key', 'platform.order_fee_applies_to_direct')->value('value') ?? true);

        $platformFee = $appliesToDirect
            ? round((float) (Setting::query()->where('key', 'platform.order_fee')->value('value') ?? 0), 2)
            : 0.0;

        return [
            'covered' => true,
            'service_zone_id' => $zone->id,
            'service_zone_name' => $zone->name_ar,
            'distance_km' => round($distanceKm, 2),
            'delivery_fee' => $fee,
            'platform_fee' => $platformFee,
            // What the captain collects in cash from the guest customer. The food is
            // settled at the restaurant (D-47..D-49), so this is the whole cash leg.
            'total' => round($fee + $platformFee, 2),
            'eta_minutes' => $zone->avg_delivery_minutes,
        ];
    }
}
