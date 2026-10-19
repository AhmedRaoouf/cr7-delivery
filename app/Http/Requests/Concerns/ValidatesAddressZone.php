<?php

namespace App\Http\Requests\Concerns;

use App\Models\ServiceZone;
use App\Support\ZoneLocator;
use Illuminate\Validation\Validator;

/**
 * The delivery fee is the zone's, and until now the zone was whatever the client said.
 *
 * Operator, 2026-09-07: «هو ممكن يختار إنه قاعد في الجيزة وهو أصلاً قاعد معايا، فيجيبوا
 * اللي هيقولوه بتاعه بالسعر». Exactly right — `zone_id` and `latitude`/`longitude` were
 * both required and nothing ever checked that they described the same place, so any
 * customer could pin their real door and file it under the cheapest area on the list.
 * In Gamasa that is «داخلي جمصة» at 20 against «زيان» at 70, on every order, forever.
 *
 * Three separate checks, because they fail for different reasons and the customer needs
 * to be told which:
 *
 * - the pin is somewhere we deliver at all;
 * - the area belongs to the town the pin is in;
 * - the area is near enough to the pin to be believable.
 *
 * The third is deliberately loose ([ZoneLocator::PICK_TOLERANCE_KM]) and is skipped
 * entirely for the flyer areas that carry no centre — D-70 says those are names, not
 * shapes. This rejects the implausible, not the merely imprecise: a customer at the edge
 * of one area ordering to the next one over is a normal Tuesday, not fraud.
 */
trait ValidatesAddressZone
{
    /**
     * Derive `city_id` from `zone_id` when the client did not send one.
     *
     * Operator ruling 2026-09-12 — «هي مناطق على طول»: the app no longer has a
     * city step, so it no longer has a `city_id` to send. Every area belongs to
     * exactly one city in the schema, so the answer was always derivable and
     * asking the client for it only created a way for the two to disagree.
     *
     * A `city_id` that *is* sent is left alone and still checked below, so an
     * older install keeps failing loudly rather than silently getting its
     * mismatch corrected for it.
     */
    protected function fillCityFromZone(): void
    {
        if ($this->filled('city_id') || ! is_numeric($this->input('zone_id'))) {
            return;
        }

        $cityId = ServiceZone::query()->whereKey($this->input('zone_id'))->value('city_id');

        if ($cityId !== null) {
            $this->merge(['city_id' => $cityId]);
        }
    }

    protected function validateZoneAgainstPoint(Validator $validator): void
    {
        $data = $validator->getData();

        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $zoneId = $data['zone_id'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng) || ! is_numeric($zoneId)) {
            return; // The `required`/`numeric` rules already have something to say.
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        $zone = ServiceZone::query()->find($zoneId);

        if ($zone === null || ! $zone->is_active) {
            $validator->errors()->add('zone_id', 'هذه المنطقة غير متاحة للتوصيل حاليًا.');

            return;
        }

        $resolved = ZoneLocator::forPoint($lat, $lng);
        $city = $resolved?->city ?? ZoneLocator::cityForPoint($lat, $lng);

        if ($city === null) {
            $validator->errors()->add('latitude', 'هذا الموقع خارج نطاق التوصيل.');

            return;
        }

        // 2026-10-19 — the city equality that stood here is gone. The area list is flat
        // (operator ruling 2026-09-12) and an installed app offers one city's slice of it,
        // so a pin in New Mansoura could only ever be filed under an area the server then
        // refused as «بعيدة عن الموقع». Whether the pick is believable is the distance
        // check below, which does not care which row of `cities` the area hangs off.
        if (! ZoneLocator::isPlausiblePick($zone, $lat, $lng)) {
            $validator->errors()->add(
                'zone_id',
                'المنطقة المختارة بعيدة عن الموقع المحدد على الخريطة — اختر المنطقة الصحيحة.',
            );
        }
    }
}
