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
 *
 * **Search does not work that way any more (D-127).** First-wins is right for a
 * reverse lookup, where the question has one answer and the best source for it
 * is known in advance. It is wrong for a search, where the question has many
 * answers and they live in different places: the local address book is first in
 * the chain, «شارع» matches ‪173‬ of its ‪224‬ street rows, and that one weak
 * substring hit suppressed Mapbox and Nominatim **entirely** — including for a
 * customer searching a place ‪200‬ km from here. So a search merges its sources
 * instead of stopping at the first; {@see PlaceResults} decides the order.
 */
class ChainGeocoder implements Geocoder, ReportsFailure
{
    /** @var bool set by the last {@see search()} */
    private bool $degraded = false;

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

    public function search(
        string $query,
        ?float $lat = null,
        ?float $lng = null,
        string $language = 'ar',
        ?string $country = null,
        int $limit = 10,
    ): array {
        $rows = [];
        $asked = 0;
        $failed = 0;

        foreach ($this->geocoders as $geocoder) {
            if (! $geocoder->isConfigured()) {
                continue;
            }

            $costsARequest = $geocoder instanceof ReportsFailure;

            // «خلي ال requests بتاعه الخريطه مقتصده». Merging every source on
            // every keystroke would spend two billed lookups on a question our
            // own rows have already answered well. A provider is asked only when
            // what is already in hand is thin — which is precisely the case the
            // old first-wins rule got wrong, because one weak local hit is thin
            // and it used to count as a complete answer.
            // Never more than the caller asked for: a picker that wants three rows
            // and already holds three has nothing to spend a request on.
            if ($costsARequest && count($rows) >= min(self::enough(), $limit)) {
                continue;
            }

            if ($costsARequest) {
                $asked++;
            }

            $rows = array_merge($rows, $geocoder->search($query, $lat, $lng, $language, $country, $limit));

            if ($costsARequest && $geocoder->lastCallFailed()) {
                $failed++;
            }
        }

        // «Nothing matched» and «everything we asked is down» are different
        // sentences on the screen, and the difference is only knowable here.
        $this->degraded = $rows === [] && $asked > 0 && $failed === $asked;

        $centre = config('maps.default_center');

        return PlaceResults::merge(
            $rows,
            $query,
            $lat ?? (float) $centre['lat'],
            $lng ?? (float) $centre['lng'],
            $limit,
        );
    }

    public function lastCallFailed(): bool
    {
        return $this->degraded;
    }

    /**
     * How many rows make the network unnecessary.
     *
     * Low enough that a thin local answer still reaches the providers — which is
     * the whole defect this replaces — and high enough that a customer typing a
     * street in the launch city spends no billed request at all.
     */
    private static function enough(): int
    {
        return max(1, (int) config('maps.search.local_is_enough', 5));
    }
}
