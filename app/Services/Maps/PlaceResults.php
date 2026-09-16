<?php

namespace App\Services\Maps;

use App\Support\ArabicSearch;
use App\Support\Geo;

/**
 * Four lists of candidates -> the one list the picker draws.
 *
 * Until D-127 there was no merge at all: {@see ChainGeocoder} returned the first
 * non-empty list it got, and the local address book is first in the chain. So a
 * single weak substring hit — «شارع» matches ‪173‬ of the ‪224‬ street rows —
 * suppressed Mapbox and Nominatim **entirely**, including for a place ‪200‬ km
 * away that neither we nor the customer had ever heard of. The first list was
 * also full of duplicates: ‪224‬ street rows hold ‪81‬ distinct names, so a
 * `LIMIT 8` on «الكورنيش» spent all eight slots on eight segments of the same
 * road and cut every other match.
 *
 * The order this imposes, in tiers:
 *
 * 1. **How well the name answers what was typed.** An exact name, then a name
 *    that starts with it, then a name that merely contains it. This is the same
 *    three tiers the catalogue search has used since «كشر» could not find
 *    «كشري», and it is what keeps «شارع الكورنيش» above «‪14‬ شارع الكورنيش».
 * 2. **Whose row it is.** A shop this platform delivers from outranks a street,
 *    which outranks a provider's guess, when the name answers equally well. The
 *    customer typing «سندوتش عمر» wants the restaurant, not the road outside it.
 * 3. **The broader thing**, when the source and the match are equal — the street
 *    before the doors along it. See {@see self::KIND_RANK}.
 * 4. **How far away it is.** Both providers treat a proximity bias as a hint
 *    that name prominence outranks, so the ordering is ours to impose — see
 *    {@see SortsByProximity}, whose job this tier now does.
 *
 * Dedupe is by folded name **and** proximity, never by name alone: «شارع
 * الكورنيش» in جمصة and «شارع الكورنيش» in الإسكندرية are two different roads
 * and both belong in a list, while the same road returned by us and by Mapbox is
 * one row and the earlier source wins it.
 */
abstract class PlaceResults
{
    /** Two rows this close with the same folded name are the same place. */
    private const SAME_PLACE_METRES = 50.0;

    /** Sources in preference order; anything unnamed sorts last. */
    private const SOURCE_RANK = ['places' => 0, 'local' => 1, 'mapbox' => 2, 'osm' => 3];

    /**
     * Within one source and one tier, the broader thing first.
     *
     * «الكورنيش» matches the street and every door along it, all equally well. The
     * street is what was meant; a door wins only when its own label *starts* with
     * what was typed — a building number — which puts it a tier higher anyway.
     */
    private const KIND_RANK = ['place' => 0, 'area' => 1, 'street' => 2, 'city' => 3, 'poi' => 4, 'address' => 5];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function merge(array $rows, string $query, float $biasLat, float $biasLng, int $limit): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $lat = (float) ($row['lat'] ?? 0);
            $lng = (float) ($row['lng'] ?? 0);

            // `0,0` is the Atlantic. A provider that answers it has answered
            // nothing, and a pin dropped there is worse than a missing row.
            if ($lat === 0.0 && $lng === 0.0) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            if (self::alreadyKept($kept, $name, $lat, $lng)) {
                continue;
            }

            // The defaults are for a row a test double built by hand; every
            // source in the chain tags its own, and `source` decides tier 2.
            $kept[] = array_merge(['kind' => 'poi', 'source' => 'osm'], $row, ['name' => $name]);
        }

        $key = static fn (array $row): array => [
            self::tier($row, $query),
            self::SOURCE_RANK[(string) $row['source']] ?? 9,
            self::KIND_RANK[(string) $row['kind']] ?? 9,
            self::distance($row, $biasLat, $biasLng),
        ];

        usort($kept, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return array_slice($kept, 0, max(1, $limit));
    }

    /**
     * The same rows, each told how far it is from the point the search was biased to.
     *
     * Published as metres and as a whole number: it is drawn as «‪820‬ م» or «‪1.2‬ كم»,
     * and neither needs a centimetre. Null when the caller sent no point — there is no
     * honest distance from «wherever جمصة is» to draw under a row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function withDistance(array $rows, ?float $lat, ?float $lng): array
    {
        return array_values(array_map(static function (array $row) use ($lat, $lng): array {
            $row['distance_m'] = $lat === null || $lng === null
                ? null
                : (int) round(Geo::metres($lat, $lng, (float) $row['lat'], (float) $row['lng']));

            return $row;
        }, $rows));
    }

    /** @param list<array<string, mixed>> $kept */
    private static function alreadyKept(array $kept, string $name, float $lat, float $lng): bool
    {
        $folded = ArabicSearch::normalize($name);

        foreach ($kept as $row) {
            if (ArabicSearch::normalize((string) $row['name']) !== $folded) {
                continue;
            }

            if (Geo::metres($lat, $lng, (float) $row['lat'], (float) $row['lng']) <= self::SAME_PLACE_METRES) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private static function tier(array $row, string $query): int
    {
        return ArabicSearch::tier((string) $row['name'], $query);
    }

    /** @param array<string, mixed> $row */
    private static function distance(array $row, float $lat, float $lng): float
    {
        return Geo::metres($lat, $lng, (float) $row['lat'], (float) $row['lng']);
    }
}
