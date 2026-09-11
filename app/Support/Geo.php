<?php

namespace App\Support;

class Geo
{
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Metres between two points, on the flat-earth approximation.
     *
     * Everything this is used for — «is the pin standing on that building»,
     * «which of these streets is nearest» — happens inside a town, where the
     * equirectangular error against {@see self::haversineKm} is under a
     * centimetre and the sqrt is the expensive part of the loop. Over ‪6 128‬
     * address points per lookup that difference is the whole cost.
     */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $y = deg2rad($lat2 - $lat1);

        return sqrt($x * $x + $y * $y) * 6371000;
    }

    /**
     * Metres from a point to the nearest point *on* a polyline — perpendicular
     * to a segment, not to a vertex.
     *
     * The distinction is the whole reason streets are stored as geometry rather
     * than as centroids: «الطريق الساحلي الدولي» is ‪14‬ km long, so its midpoint
     * is several kilometres from most of the road, and a nearest-centroid answer
     * names the wrong street for half the town.
     *
     * @param  list<array{0: float, 1: float}>  $polyline  [lat, lng] pairs
     */
    public static function metresToPolyline(float $lat, float $lng, array $polyline): float
    {
        $best = INF;
        $scale = cos(deg2rad($lat));

        for ($i = 0, $last = count($polyline) - 1; $i < $last; $i++) {
            // Work in degrees with longitude scaled to match latitude, so the
            // projection below is a plain 2-D one, then convert once at the end.
            $ax = ($polyline[$i][1] - $lng) * $scale;
            $ay = $polyline[$i][0] - $lat;
            $bx = ($polyline[$i + 1][1] - $lng) * $scale;
            $by = $polyline[$i + 1][0] - $lat;

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $lengthSquared = $dx * $dx + $dy * $dy;

            // `t` clamped to [0, 1] is what keeps the answer on the segment
            // rather than on the infinite line through it.
            $t = $lengthSquared === 0.0
                ? 0.0
                : max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $lengthSquared));

            $x = $ax + $t * $dx;
            $y = $ay + $t * $dy;

            $best = min($best, $x * $x + $y * $y);
        }

        return $best === INF ? INF : sqrt($best) * deg2rad(1.0) * 6371000;
    }

    /**
     * Ray-casting point-in-polygon test. `$polygon` is a GeoJSON-style ring:
     * an array of [lng, lat] pairs (the outer ring only — holes are not supported).
     */
    public static function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$lngI, $latI] = $polygon[$i];
            [$lngJ, $latJ] = $polygon[$j];

            $intersects = (($latI > $lat) !== ($latJ > $lat))
                && ($lng < ($lngJ - $lngI) * ($lat - $latI) / (($latJ - $latI) ?: PHP_FLOAT_EPSILON) + $lngI);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
