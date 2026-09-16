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
 * away that neither we nor the customer had ever heard of.
 *
 * **Near beats far, first.** The first merge ranked match quality above
 * distance at any scale, and a customer in جمصة noticed at once: «صيدلية» opened
 * on three OpenStreetMap pharmacies named exactly «صيدلية» at ‪150‬–‪165‬ km — in
 * Cairo — above the four pharmacies this platform delivers from, ‪2‬ km away;
 * «الاندلس» opened on a POI ‪28‬ km off above «منطقة الأندلس» at ‪537‬ m. An exact
 * name is not a better answer than a place the customer can reach. So rows are
 * bucketed by distance before anything else is compared:
 *
 * 1. **Near or not** — within `maps.search.near_km` of the point the search was
 *    biased to. A near row outranks every far one, whatever its match.
 * 2. **How well the name answers what was typed.** Exact, then prefix, then
 *    contains — the catalogue search's three tiers.
 * 3. **Whose row it is.** A shop this platform delivers from, then the town's
 *    own address book, then a provider's guess.
 * 4. **The broader thing** — the street before the doors along it.
 * 5. **Distance.**
 *
 * And two things are dropped rather than ranked:
 *
 * - **A provider row past `maps.search.far_km`, when anything is near** — and a
 *   far provider row whose name something near already carries. Nobody in جمصة
 *   searching «صيدلية» wants Cairo. When nothing is near they are the only
 *   answer there is, and «المنصورة» from جمصة has to go on working.
 * - **A generic POI that is not near.** An OpenStreetMap node named «صيدلية»,
 *   «مسجد» or «مدرسة» and nothing else is only a destination when it is close
 *   enough that its address line tells it apart from the others — and when it is
 *   kept its name no longer counts as an exact match, because a name that is the
 *   category word says nothing about which one it is.
 *
 * Dedupe is by folded name and proximity, never by name alone: «شارع الكورنيش»
 * in جمصة and «شارع الكورنيش» in رأس البر are two different roads. But «near» is
 * a different distance for a road than for a shop — see
 * {@see self::sameThing()}.
 */
abstract class PlaceResults
{
    /** Two points this close with the same folded name are the same point. */
    private const SAME_POINT_METRES = 50.0;

    /** Sources in preference order; anything unnamed sorts last. */
    private const SOURCE_RANK = ['places' => 0, 'local' => 1, 'mapbox' => 2, 'osm' => 3];

    /** The sources that are this platform's own rows rather than a provider's. */
    private const OURS = ['places', 'local'];

    /**
     * Within one bucket, source and tier, the broader thing first.
     *
     * «الكورنيش» matches the street and every door along it, all equally well. The
     * street is what was meant; a door wins only when its own label *starts* with
     * what was typed — a building number — which puts it a tier higher anyway.
     */
    private const KIND_RANK = ['place' => 0, 'area' => 1, 'street' => 2, 'city' => 3, 'poi' => 4, 'address' => 5];

    /** Kinds that are an extent rather than a point — see {@see self::sameThing()}. */
    private const EXTENTS = ['street', 'area', 'city'];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function merge(array $rows, string $query, float $biasLat, float $biasLng, int $limit): array
    {
        $near = self::metresFor('near_km', 15.0);
        $far = self::metresFor('far_km', 60.0);
        $generic = self::genericNames();

        $candidates = [];

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

            // The defaults are for a row a test double built by hand; every
            // source in the chain tags its own.
            $row = array_merge(['kind' => 'poi', 'source' => 'osm'], $row, ['name' => $name]);

            $candidate = [
                'row' => $row,
                'folded' => ArabicSearch::normalize($name),
                'distance' => Geo::metres($biasLat, $biasLng, $lat, $lng),
                'generic' => $row['kind'] === 'poi' && self::isGeneric($name, $generic),
            ];

            if ($candidate['generic'] && $candidate['distance'] > $near) {
                continue;
            }

            $candidates[] = $candidate;
        }

        $kept = self::dedupe($candidates, self::metresFor('same_extent_km', 3.0));

        $nearNames = [];

        foreach ($kept as $candidate) {
            if ($candidate['distance'] <= $near) {
                $nearNames[$candidate['folded']] = true;
            }
        }

        if ($nearNames !== []) {
            // A provider row is kept when it is near, or when it is within
            // `far_km` **and** nothing near already carries its name. «شارع
            // الكورنيش» ‪18‬–‪20‬ km away is رأس البر's corniche, three OSM ways
            // more than ‪3‬ km apart; to a customer standing on جمصة's it is the
            // same line printed three more times, and a customer who means the
            // other one types «الكورنيش رأس البر».
            $kept = array_values(array_filter(
                $kept,
                static fn (array $c): bool => in_array($c['row']['source'], self::OURS, true)
                    || $c['distance'] <= $near
                    || ($c['distance'] <= $far && ! isset($nearNames[$c['folded']])),
            ));
        }

        $key = static fn (array $c): array => [
            $c['distance'] <= $near ? 0 : 1,
            $c['generic'] ? 2 : ArabicSearch::tier((string) $c['row']['name'], $query),
            self::SOURCE_RANK[(string) $c['row']['source']] ?? 9,
            self::KIND_RANK[(string) $c['row']['kind']] ?? 9,
            $c['distance'],
        ];

        usort($kept, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return array_map(
            static fn (array $c): array => $c['row'],
            array_slice($kept, 0, max(1, $limit)),
        );
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

    /**
     * One row per real thing, keeping ours over a provider's and else the nearest.
     *
     * Grouped by **single linkage**: a row joins a group when it is the same thing as
     * *any* member already in it, not only the first. A road comes back from
     * Nominatim as one row per OSM way — «شارع الكورنيش» at ‪867‬, ‪965‬, ‪2 261‬ and
     * ‪2 735‬ m — and each segment is close to the next while the two ends are
     * kilometres apart; comparing only with the kept row would split one road into
     * two wherever the customer happens to stand between its ends.
     *
     * @param  list<array{row: array<string, mixed>, folded: string, distance: float, generic: bool}>  $candidates
     * @return list<array{row: array<string, mixed>, folded: string, distance: float, generic: bool}>
     */
    private static function dedupe(array $candidates, float $extentMetres): array
    {
        $groups = [];

        foreach ($candidates as $candidate) {
            $joined = null;

            foreach ($groups as $index => $group) {
                if ($group['best']['folded'] !== $candidate['folded']) {
                    continue;
                }

                foreach ($group['members'] as $member) {
                    if (self::sameThing($member, $candidate, $extentMetres)) {
                        $joined = $index;

                        break 2;
                    }
                }
            }

            if ($joined === null) {
                $groups[] = ['best' => $candidate, 'members' => [$candidate]];

                continue;
            }

            $groups[$joined]['members'][] = $candidate;

            if (self::preferred($candidate, $groups[$joined]['best'])) {
                $groups[$joined]['best'] = $candidate;
            }
        }

        return array_map(static fn (array $group): array => $group['best'], $groups);
    }

    /**
     * Whether two rows of the same folded name are one place.
     *
     * ‪50‬ m is right for a point — a shop, a door, a mosque — and wrong for a road
     * or an area, which is kilometres long: «شارع الأندلس» from the town's address
     * book and «شارع الاندلس» from Nominatim sat ‪158‬ m apart and were listed twice.
     * If either row is an extent, the radius is `maps.search.same_extent_km`, which
     * is still far less than the distance to the next town's road of the same name.
     *
     * @param  array{row: array<string, mixed>, folded: string, distance: float, generic: bool}  $a
     * @param  array{row: array<string, mixed>, folded: string, distance: float, generic: bool}  $b
     */
    private static function sameThing(array $a, array $b, float $extentMetres): bool
    {
        $extent = in_array($a['row']['kind'], self::EXTENTS, true) || in_array($b['row']['kind'], self::EXTENTS, true);
        $radius = $extent ? $extentMetres : self::SAME_POINT_METRES;

        return Geo::metres(
            (float) $a['row']['lat'],
            (float) $a['row']['lng'],
            (float) $b['row']['lat'],
            (float) $b['row']['lng'],
        ) <= $radius;
    }

    /**
     * Ours over a provider's; then the nearer.
     *
     * @param  array{row: array<string, mixed>, distance: float}  $candidate
     * @param  array{row: array<string, mixed>, distance: float}  $current
     */
    private static function preferred(array $candidate, array $current): bool
    {
        $rank = static fn (array $c): array => [
            in_array($c['row']['source'], self::OURS, true) ? 0 : 1,
            $c['distance'],
        ];

        return $rank($candidate) < $rank($current);
    }

    /**
     * A POI named for what it is rather than which one it is — «صيدلية», «الصيدلية».
     *
     * @param  list<string>  $generic  folded, without the definite article
     */
    private static function isGeneric(string $name, array $generic): bool
    {
        return in_array((string) preg_replace('/^ال/u', '', ArabicSearch::normalize($name)), $generic, true);
    }

    /** @return list<string> */
    private static function genericNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $word): string => (string) preg_replace('/^ال/u', '', ArabicSearch::normalize($word)),
            (array) config('maps.search.generic_names', []),
        )));
    }

    private static function metresFor(string $key, float $defaultKm): float
    {
        return max(0.0, (float) config("maps.search.{$key}", $defaultKm)) * 1000;
    }
}
