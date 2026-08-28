<?php

namespace App\Services\Maps;

/**
 * What the platform runs with before a Mapbox token exists.
 *
 * It is deliberately silent rather than throwing: `/geo/reverse` answering
 * `{"address": null}` lets the address picker ship its street line now and
 * start filling it the day the token lands, with no client release.
 */
class NullGeocoder implements Geocoder
{
    public function reverse(float $lat, float $lng, string $language = 'ar'): ?array
    {
        return null;
    }

    public function search(string $query, ?float $lat = null, ?float $lng = null, string $language = 'ar'): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
