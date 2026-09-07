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

        if ($zone->city_id !== $city->id) {
            $validator->errors()->add(
                'zone_id',
                'المنطقة المختارة لا تتبع '.$city->name_ar.' — اختر منطقة داخل المدينة التي يقع فيها العنوان.',
            );

            return;
        }

        if (! ZoneLocator::isPlausiblePick($zone, $lat, $lng)) {
            $validator->errors()->add(
                'zone_id',
                'المنطقة المختارة بعيدة عن الموقع المحدد على الخريطة — اختر المنطقة الصحيحة.',
            );
        }
    }
}
