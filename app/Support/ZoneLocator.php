<?php

namespace App\Support;

use App\Models\City;
use App\Models\ServiceZone;
use Illuminate\Database\Eloquent\Collection;

/**
 * «Which of our areas is this point in?» — asked in four places, answered four ways.
 *
 * `GeoController::resolve`, `QuoteDirectOrderAction` and the admin pricing preview each
 * carried their own copy of the containment walk, and the preview's copy disagreed with
 * the other two (it matched on the *restaurant's* zone, not the pin's). A preview that
 * disagrees with checkout prints a fee no customer is ever charged.
 *
 * The lookup is deliberately a full scan of the active zones rather than a spatial query:
 * there are tens of them, not thousands, and MySQL's spatial types are not in the schema.
 *
 * D-70 — a zone may have a polygon, a centre-and-radius, or **neither**. The priced areas
 * off the operator's printed flyer («جنة 3», «الصناعية الرابعة») are names with no shape
 * at all, so no point ever falls inside one and [forPoint] returns null for them by
 * design. Coverage is then the city circle, and the price is whichever area the customer
 * picks — which is what [selectableFor] lists and [isPlausiblePick] polices.
 */
class ZoneLocator
{
    /**
     * How far from a zone's own centre a customer may claim to be and still be believed.
     *
     * Only applied to zones that actually carry a centre. It is a fraud guard, not a
     * geofence: the point comes from the customer's device, the areas overlap in reality,
     * and a customer standing at the edge of «مايو» ordering to their sister's flat in
     * «دلتا» is a normal thing to do. It exists to stop the pick that is not plausible at
     * all — «داخلي جمصة» at 20 for an address that resolves 40 km away in زيان at 70.
     */
    public const PICK_TOLERANCE_KM = 12.0;

    /** The zone whose shape contains the point, or null when no shape does. */
    public static function forPoint(float $lat, float $lng): ?ServiceZone
    {
        return ServiceZone::query()
            ->with('city')
            ->where('is_active', true)
            ->get()
            ->first(fn (ServiceZone $zone) => self::contains($zone, $lat, $lng));
    }

    /** The city whose circle contains the point — the fallback when no zone shape does. */
    public static function cityForPoint(float $lat, float $lng): ?City
    {
        return City::query()
            ->where('is_active', true)
            ->whereNotNull('radius_km')
            ->get()
            ->map(fn (City $city) => [
                'city' => $city,
                'distance' => Geo::haversineKm((float) $city->center_lat, (float) $city->center_lng, $lat, $lng),
            ])
            ->filter(fn (array $row) => $row['distance'] <= (float) $row['city']->radius_km)
            ->sortBy('distance')
            ->first()['city'] ?? null;
    }

    public static function contains(ServiceZone $zone, float $lat, float $lng): bool
    {
        if ($zone->polygon) {
            return Geo::pointInPolygon($lat, $lng, $zone->polygon[0] ?? $zone->polygon);
        }

        if ($zone->radius_km) {
            return Geo::haversineKm((float) $zone->center_lat, (float) $zone->center_lng, $lat, $lng)
                <= (float) $zone->radius_km;
        }

        return false;
    }

    /**
     * Is it believable that someone at this point is ordering to this zone?
     *
     * True for any zone with no centre — there is nothing to measure against, and
     * refusing on absence of data would reject every flyer area. See the tolerance note.
     */
    public static function isPlausiblePick(ServiceZone $zone, float $lat, float $lng): bool
    {
        if (self::contains($zone, $lat, $lng)) {
            return true;
        }

        if ($zone->center_lat === null || $zone->center_lng === null) {
            return true;
        }

        return Geo::haversineKm((float) $zone->center_lat, (float) $zone->center_lng, $lat, $lng)
            <= self::PICK_TOLERANCE_KM;
    }

    /**
     * The areas a customer may pick in a city, cheapest first — the flyer's own order.
     *
     * @return Collection<int, ServiceZone>
     */
    public static function selectableFor(int $cityId)
    {
        return ServiceZone::query()
            ->where('city_id', $cityId)
            ->where('is_active', true)
            ->where('is_selectable', true)
            ->orderBy('sort_order')
            ->orderBy('delivery_fee')
            ->get();
    }
}
