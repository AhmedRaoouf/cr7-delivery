<?php

namespace App\Services\Maps;

/**
 * Nearest first, whatever the provider thought.
 *
 * `GET /geo/search` exists for one job: a customer standing in جمصة is typing
 * the name of the street they live on. Both providers accept a bias — Mapbox a
 * `proximity` point, Nominatim a `viewbox` — and both treat it as a hint that
 * loses to name prominence. Searching «الكورنيش» from the town centre on
 * 2026-09-11 answered, in order: كوبري الجيش in الإسماعيلية, شارع الكورنيش
 * الجديد in مرسى مطروح, and only third the one ‪7‬ km away.
 *
 * A hard `bbox` would fix the order by throwing away everything outside it,
 * which is the wrong trade for a platform that launches in one town and then
 * adds two more (doc 01): a box tight enough to rank جمصة first is tight enough
 * to answer nothing at all for a customer in ‪15‬ مايو. Sorting removes no
 * result and reorders every one of them.
 *
 * Equirectangular, not haversine: over the tens of kilometres this ever
 * compares, the error is far below the gap between any two candidates, and the
 * comparison is on squared degrees so nothing takes a square root either. The
 * `cos` term is what keeps a degree of longitude from counting as much as a
 * degree of latitude — without it, at Egypt's latitude, east-west distance is
 * overstated by about ‪15‬ %.
 */
trait SortsByProximity
{
    /**
     * @param  list<array{name: string, address: string, lat: float, lng: float}>  $rows
     * @return list<array{name: string, address: string, lat: float, lng: float}>
     */
    protected static function nearestFirst(array $rows, float $lat, float $lng): array
    {
        $scale = cos(deg2rad($lat)) ** 2;

        usort($rows, function (array $a, array $b) use ($lat, $lng, $scale) {
            $distance = static function (array $row) use ($lat, $lng, $scale): float {
                $dLat = $row['lat'] - $lat;
                $dLng = $row['lng'] - $lng;

                return $dLat * $dLat + $dLng * $dLng * $scale;
            };

            return $distance($a) <=> $distance($b);
        });

        return array_values($rows);
    }
}
