<?php

namespace App\Services\Maps;

/**
 * The providers in preference order, and the first one that actually answers.
 *
 * D-84 said «Mapbox when there is a token, Nominatim when there is not», and
 * bound exactly one of them for the life of the process. That is one provider
 * too few: on 2026-09-11 the deployed server had a perfectly good Mapbox token,
 * `MapboxGeocoder` was bound, a malformed query made Mapbox answer 422 to every
 * reverse lookup, and `GET /geo/reverse` returned `{"address": null}` — with a
 * free geocoder sitting in the same directory, configured, and never asked.
 *
 * A key is a preference, not a dependency. If the paid provider is down, rate
 * limited, out of quota or being sent something it dislikes, the answer should
 * come from the keyless one rather than not come at all. The customer's pin gets
 * a name either way, which is the whole of what the screen needs.
 *
 * «Answered» means a non-empty line, not merely a non-null return: a provider
 * that hands back an empty `address` has told us nothing, and the next one is
 * still worth asking.
 */
class ChainGeocoder implements Geocoder
{
    /** @param list<Geocoder> $geocoders in preference order */
    public function __construct(private readonly array $geocoders) {}

    public function isConfigured(): bool
    {
        foreach ($this->geocoders as $geocoder) {
            if ($geocoder->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    public function reverse(float $lat, float $lng, string $language = 'ar'): ?array
    {
        foreach ($this->geocoders as $geocoder) {
            if (! $geocoder->isConfigured()) {
                continue;
            }

            $row = $geocoder->reverse($lat, $lng, $language);

            if (is_array($row) && trim((string) ($row['address'] ?? '')) !== '') {
                return $row;
            }
        }

        return null;
    }

    public function search(string $query, ?float $lat = null, ?float $lng = null, string $language = 'ar'): array
    {
        foreach ($this->geocoders as $geocoder) {
            if (! $geocoder->isConfigured()) {
                continue;
            }

            $rows = $geocoder->search($query, $lat, $lng, $language);

            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }
}
