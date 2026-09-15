<?php

namespace App\Support\Notifications;

use App\Models\Driver;
use App\Models\Order;
use App\Support\DriverEarningCalculator;
use App\Support\DriverTrip;
use App\Support\Geo;

/**
 * doc 62 §9 — the offer, said in full.
 *
 * Operator, 2026-09-13: «ساعات بلاقي ان بييجي notification للكابتن تقله ان الارباح صفر
 * والمسافه صفر». Both zeros had a cause, and neither was a bug in the arithmetic:
 *
 * - **The earning** was ‪0.00‬ on every customer's first order, because the first order
 *   ships free and D-105 pays nobody on a waived fee — which is the ruling, but the push
 *   said «أرباحك ‪0.00‬ ج.م» and nothing else. It now says why.
 * - **The distance** was the captain's distance to the place, and `offerBacklogTo()` —
 *   the path a captain coming on shift is offered every waiting order through — sent it
 *   as `null` on every offer, which the push printed as «—» and the app as ‪0‬. It is
 *   computed from the captain's fix wherever there is one, and a segment with nothing
 *   to say is dropped from the sentence rather than printed as nothing.
 *
 * The trip distance — place to door — is the one a captain actually weighs, and was
 * never in the push at all.
 */
final class OfferContext
{
    /** @return array{summary: array{ar: string, en: string}, earning: float, to_place_km: float|null, trip_km: float|null, on_trip: bool} */
    public static function for(Order $order, Driver $driver, ?float $toPlaceKm = null): array
    {
        $toPlaceKm ??= self::toPlaceKm($order, $driver);
        $tripKm = $order->distance_km === null || (float) $order->distance_km <= 0 ? null : (float) $order->distance_km;
        $earning = DriverEarningCalculator::calculate($order, $driver);

        $place = (string) ($order->restaurant?->name_ar ?? $order->restaurant_name_snapshot ?? '');
        $placeEn = (string) ($order->restaurant?->name_en ?: $place);
        $area = $order->serviceZone?->name_ar;
        $areaEn = $order->serviceZone?->name_en ?: $area;

        $ar = [$area ? $place.' ← '.$area : $place];
        $en = [$areaEn ? $placeEn.' → '.$areaEn : $placeEn];

        if ($toPlaceKm !== null) {
            $ar[] = 'يبعد عنك ‪'.self::km($toPlaceKm).'‬ كم';
            $en[] = self::km($toPlaceKm).' km from you';
        }

        if ($tripKm !== null) {
            $ar[] = 'الرحلة ‪'.self::km($tripKm).'‬ كم';
            $en[] = 'trip '.self::km($tripKm).' km';
        }

        $ar[] = 'أرباحك ‪'.number_format($earning, 2).'‬ ج.م';
        $en[] = 'you earn '.number_format($earning, 2).' EGP';

        $reason = self::reason($order->free_delivery_reason);

        if ($reason !== null && (float) $order->delivery_fee_share <= 0) {
            $ar[count($ar) - 1] .= ' — توصيل مجاني ('.$reason['ar'].')';
            $en[count($en) - 1] .= ' — free delivery ('.$reason['en'].')';
        }

        if ($order->payment_method?->value === 'cash' && ($pay = $order->placePaidAtPickup()) > 0) {
            $ar[] = 'تدفع للمكان ‪'.number_format($pay, 2).'‬ ج.م';
            $en[] = 'you pay the place '.number_format($pay, 2).' EGP';
        }

        // doc 63 §2.3 — an offer to a captain already carrying orders says so: it joins
        // the trip they are on, it does not start a new one.
        $onTrip = $driver->exists && DriverTrip::query($driver)->whereKeyNot($order->id)->exists();

        if ($onTrip) {
            $ar[] = 'ضمن رحلتك الحالية';
            $en[] = 'on your current trip';
        }

        return [
            'summary' => ['ar' => implode(' · ', array_filter($ar)), 'en' => implode(' · ', array_filter($en))],
            'earning' => $earning,
            'to_place_km' => $toPlaceKm,
            'trip_km' => $tripKm,
            'on_trip' => $onTrip,
        ];
    }

    public static function toPlaceKm(Order $order, Driver $driver): ?float
    {
        $restaurant = $order->restaurant;

        if (! $driver->hasFix() || $restaurant === null || $restaurant->latitude === null || $restaurant->longitude === null) {
            return null;
        }

        return round(Geo::haversineKm(
            (float) $driver->current_lat,
            (float) $driver->current_lng,
            (float) $restaurant->latitude,
            (float) $restaurant->longitude,
        ), 2);
    }

    /** @return array{ar: string, en: string}|null */
    public static function reason(?string $code): ?array
    {
        return match ($code) {
            'first_order' => ['ar' => 'أول طلب للعميل', 'en' => "the customer's first order"],
            'threshold' => ['ar' => 'تجاوز الطلب حد التوصيل المجاني', 'en' => 'over the free-delivery threshold'],
            'promo' => ['ar' => 'كوبون توصيل مجاني', 'en' => 'a free-delivery coupon'],
            default => null,
        };
    }

    private static function km(float $km): string
    {
        return number_format($km, 1);
    }
}
