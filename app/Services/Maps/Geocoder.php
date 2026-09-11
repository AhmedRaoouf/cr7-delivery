<?php

namespace App\Services\Maps;

interface Geocoder
{
    /**
     * Coordinates -> a human street line.
     *
     * Returns null when no provider is configured or the provider fails. A map
     * pin without a street label is still a usable pin, so a geocoding outage
     * must never be an error the customer has to clear.
     *
     * `address` is the composed line the picker prints; the parts are sent
     * beside it because `CUS-24` prefills «الشارع» from `street` and cannot cut
     * it back out of a sentence (D-84).
     *
     * @return array{address: string, place: ?string, street?: ?string, house_number?: ?string, neighborhood: ?string, city: ?string}|null
     */
    public function reverse(float $lat, float $lng, string $language = 'ar'): ?array;

    /**
     * Free text -> candidate places, nearest-to-جمصة first.
     *
     * @return list<array{name: string, address: string, lat: float, lng: float}>
     */
    public function search(string $query, ?float $lat = null, ?float $lng = null, string $language = 'ar'): array;

    public function isConfigured(): bool;
}
