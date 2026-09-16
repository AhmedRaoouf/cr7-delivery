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
     * D-127 — every row says what it is and where it came from:
     *
     * - `kind` — `place|street|area|city|address|poi`. It drives the row's icon, and a
     *   source that cannot tell says `poi`.
     * - `source` — `places|local|mapbox|osm`. For us, not for the screen: it is how a
     *   thin answer is told from a dead provider after the fact.
     * - `place_id` — only on `kind: place`, and it is a `restaurants.id`. A row that
     *   carries one is a shop this platform delivers from, so the client can open it
     *   rather than merely drop a pin on it.
     *
     * `$country` is an ISO-3166-1 alpha-2 filter for the providers that take one;
     * `$limit` is how many rows the caller can use, so a source need not compose more.
     *
     * @return list<array{name: string, address: string, lat: float, lng: float, kind?: string, source?: string, place_id?: int}>
     */
    public function search(
        string $query,
        ?float $lat = null,
        ?float $lng = null,
        string $language = 'ar',
        ?string $country = null,
        int $limit = 10,
    ): array;

    public function isConfigured(): bool;
}
