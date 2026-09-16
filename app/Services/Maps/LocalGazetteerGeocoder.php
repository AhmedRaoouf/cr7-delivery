<?php

namespace App\Services\Maps;

use App\Support\ArabicSearch;
use App\Support\Geo;
use Database\Seeders\GazetteerSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The launch city's own address book, asked before any provider is.
 *
 * Measured on production 2026-09-12, four pins across Gamasa. Mapbox answered a
 * street for all four and `neighborhood: null` for all four; the screen had
 * nothing to print as the area but the **delivery zone**, and the only zone
 * whose circle covers the town is «الشاطئ». That is the whole of the operator's
 * «مش دايمًا ألاقي جمصة الشاطئ» — the beach is two rows of buildings and the app
 * was calling the entire town by its name.
 *
 * Nominatim, asked about the same point, answers «منطقة آمون». It was in the
 * chain and configured, and it was never asked, because {@see ChainGeocoder}
 * stops at the first non-empty line and Mapbox's street-only line is non-empty.
 * Reordering the two would only trade one town's gaps for another's.
 *
 * So neither provider is the answer. OpenStreetMap's raw data for this one town
 * carries **‪6 128‬ building address points**, ‪224‬ named street polylines and ‪32‬
 * named areas — more than either geocoding API exposes. Seeded into three local
 * tables it answers in a millisecond, with no network, no key and no quota, and
 * it names the building rather than guessing at it.
 *
 * Three rules this class is built on, each of them a way the previous answers
 * were wrong:
 *
 * - **A house number is only quoted when the pin is on that house.** Mapbox
 *   returned «‪18‬ شارع آمون» for a point ‪100‬ m from anything, which is an
 *   interpolation, and a door number that is off by one block sends a captain to
 *   a stranger. Past {@see self::HOUSE_NUMBER_METRES} the number is dropped and
 *   the street stands alone.
 * - **A street is named by its nearest segment, not its centroid.** «الطريق
 *   الساحلي الدولي» is ‪14‬ km long; its midpoint is nowhere near most of it.
 * - **The area always comes out.** It is what the customer asked for and the
 *   one field the providers never filled.
 *
 * Outside {@see self::BOUNDS} this returns null on purpose — we are
 * authoritative where we launch, and the providers are better everywhere else.
 *
 * @see https://osm.org/copyright — ODbL 1.0.
 */
class LocalGazetteerGeocoder implements Geocoder
{
    /** Seeded coverage: [south, west, north, east]. */
    private const BOUNDS = [31.40, 31.47, 31.48, 31.59];

    /** Beyond this the nearest mapped door is a different building. */
    private const HOUSE_NUMBER_METRES = 40.0;

    /** Beyond this the pin is not standing on that street. */
    private const STREET_METRES = 70.0;

    /** A ~‪220‬ m box: wide enough to always contain a door, small enough to stay cheap. */
    private const POINT_BOX_DEGREES = 0.0020;

    /** A ~‪130‬ m box around the pin, widened per-row by the street's own bbox. */
    private const STREET_BOX_DEGREES = 0.0012;

    /**
     * How far the *nearest named area* may be before it stops being this pin's.
     *
     * The gazetteer's bbox is larger than its coverage: villages like الركابية
     * and زيان sit inside it and OSM has no named area for either. Without a
     * cap the nearest-centroid fallback reached ‪4‬ km across open farmland and
     * answered «منطقة صلاح الدين» for a pin in الركابية — a real area name, on
     * the wrong side of town, which is the most convincing kind of wrong.
     *
     * Beyond this the area comes back null, the whole lookup usually returns
     * null with it, and the chain falls through to the providers — who may
     * genuinely know the village.
     */
    private const AREA_METRES = 1200.0;

    /** A box a little wider than {@see self::AREA_METRES}, to prefilter in SQL. */
    private const AREA_BOX_DEGREES = 0.013;

    /** «Are there rows at all» — see {@see self::isConfigured()}. */
    public const READY_KEY = 'geo:gazetteer:ready';

    /** Bumped by a re-seed; every cached search key carries it. */
    public const STAMP_KEY = 'geo:gazetteer:stamp';

    /**
     * How many door rows a query may pull back before the ranking runs.
     *
     * ‪6 128‬ doors and ‪81‬ street names between them, so «شارع الكورنيش» matches
     * every door on that road. They are ordered prefix-first in SQL so the cut
     * falls on the door numbers rather than on anything a person typed, and the
     * survivors are then sorted by distance in PHP.
     */
    private const POINT_CANDIDATES = 300;

    /** The same ceiling for the two small tables; ‪224‬ and ‪32‬ rows total. */
    private const NAME_CANDIDATES = 250;

    public function isConfigured(): bool
    {
        // One `exists()`, remembered for an hour — this is asked on every reverse
        // lookup and the answer changes only on a re-seed.
        //
        // **Only a `true` is remembered.** `Cache::remember()` stores whichever
        // answer it gets, so a lookup that ran a minute before the seeder left
        // this geocoder switched off for the rest of the hour while the rows sat
        // there: three tables full of addresses and every pin named by a
        // provider. A `false` costs one indexed `exists()` per lookup, which is
        // exactly what an unseeded install should pay.
        if (Cache::get(self::READY_KEY) === true) {
            return true;
        }

        $ready = DB::table('gazetteer_points')->exists();

        if ($ready) {
            Cache::put(self::READY_KEY, true, now()->addHour());
        }

        return $ready;
    }

    /**
     * A token that changes whenever the tables are reloaded.
     *
     * Every cached search answer is keyed on it, because there is no
     * pattern-delete that works on every cache store and a search answered out
     * of the old rows would otherwise outlive the reload by a week.
     * {@see GazetteerSeeder} drops it.
     */
    private static function stamp(): string
    {
        // Random rather than a timestamp: two re-seeds inside one second — a test,
        // or a deploy that migrates twice — would otherwise mint the same stamp and
        // invalidate nothing.
        return (string) Cache::rememberForever(self::STAMP_KEY, static fn (): string => bin2hex(random_bytes(6)));
    }

    public function reverse(float $lat, float $lng, string $language = 'ar'): ?array
    {
        if (! $this->covers($lat, $lng) || ! $this->isConfigured()) {
            return null;
        }

        $point = $this->nearestPoint($lat, $lng);
        $street = $this->nearestStreet($lat, $lng);
        $area = $this->areaFor($point, $lat, $lng);

        $houseNumber = $point !== null && $point['distance'] <= self::HOUSE_NUMBER_METRES
            ? self::trimmed($point['row']->house_number)
            : null;

        $streetName = $street !== null && $street['distance'] <= self::STREET_METRES
            ? $street['name']
            // A door that carries its own street tag beats a road we are not
            // standing on — the planned districts number the buildings and
            // leave most of the internal roads unnamed.
            : ($point !== null && $point['distance'] <= self::HOUSE_NUMBER_METRES
                ? self::trimmed($point['row']->street)
                : null);

        $line = $this->composeLine($houseNumber, $streetName, $language);

        $parts = array_values(array_filter([$line, $area]));

        if ($parts === []) {
            // Inside the bbox but on unmapped ground — the salt flats east of
            // the coastal road. The providers may still know something.
            return null;
        }

        return [
            'address' => implode('، ', $parts),
            'place' => $line ?? $area,
            'street' => $streetName,
            'house_number' => $houseNumber,
            'neighborhood' => $area,
            'city' => $point !== null ? self::trimmed($point['row']->city) : null,
        ];
    }

    /**
     * D-127 — the town's own address book, asked the way a person types.
     *
     * Four defects sat in the ten lines this replaces, and each of them made the
     * endpoint answer ‪200‬ with an empty list, which is indistinguishable from
     * «this place does not exist»:
     *
     * - **`LIKE '%…%'` on the raw column.** No folding, so «الاندلس» — what the
     *   Arabic keyboard actually produces — never matched the stored «منطقة
     *   الأندلس», and «ايزيس» never matched «منطقة إيزيس». Both sides go through
     *   {@see ArabicSearch} now, against the folded column the seeder writes, and
     *   the needle's own `%` and `_` are escaped with a declared `ESCAPE`
     *   character instead of leaning on MySQL's default backslash, which the
     *   query never declared and SQLite does not have.
     * - **`limit(8)` spent on duplicates.** The street table is one row per OSM
     *   way: ‪224‬ rows, ‪81‬ names, «طريق جمصة — كفر الوسطاني» ‪32‬ times. Searching
     *   «الكورنيش» returned eight identical lines pointing at eight bbox
     *   midpoints. One row per name now, at the surveyed vertex of that road
     *   nearest the customer.
     * - **The doors were never searched.** ‪6 128‬ of them, and the entire
     *   searchable vocabulary of the launch city was the ‪113‬ street and area
     *   names above.
     * - **No `covers()` check.** It guards `reverse()` and not this, so a
     *   customer in الإسكندرية typing «شارع» got Gamasa's streets — and, before
     *   the chain learned to merge, *only* Gamasa's streets.
     */
    public function search(
        string $query,
        ?float $lat = null,
        ?float $lng = null,
        string $language = 'ar',
        ?string $country = null,
        int $limit = 10,
    ): array {
        $tokens = ArabicSearch::tokens($query);

        if ($tokens === [] || mb_strlen(trim($query)) < 2 || ! $this->isConfigured()) {
            return [];
        }

        $centre = config('maps.default_center');
        $biasLat = $lat ?? (float) $centre['lat'];
        $biasLng = $lng ?? (float) $centre['lng'];

        // We are authoritative where we launch and nowhere else. `reverse()` has
        // said so since the tables were seeded; this said the opposite.
        if (! $this->covers($biasLat, $biasLng)) {
            return [];
        }

        $key = sprintf(
            'geo:gaz:fwd:%s:%s:%s:%.3f,%.3f:%d',
            self::stamp(),
            $language,
            md5(implode(' ', $tokens)),
            $biasLat,
            $biasLng,
            $limit,
        );

        return Cache::remember(
            $key,
            now()->addDays((int) config('maps.cache.search_days', 7)),
            fn (): array => $this->lookUp($tokens, $query, $biasLat, $biasLng, $language, $limit),
        );
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function lookUp(array $tokens, string $query, float $biasLat, float $biasLng, string $language, int $limit): array
    {
        $areas = $this->matchingAreas($tokens);

        $rows = array_merge(
            $areas,
            $this->matchingStreets($tokens, $biasLat, $biasLng),
            $this->matchingDoors($tokens, $query, $biasLat, $biasLng, $language, $limit, $areas),
        );

        return PlaceResults::merge($rows, $query, $biasLat, $biasLng, $limit);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function matchingAreas(array $tokens): array
    {
        $query = DB::table('gazetteer_areas');
        ArabicSearch::applyNormalized($query, ['name_normalized'], $tokens);

        return $query
            ->limit(self::NAME_CANDIDATES)
            ->get(['name', 'lat', 'lng', 'city'])
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'address' => trim($row->name.($row->city === null ? '' : '، '.$row->city), '، '),
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'kind' => 'area',
                'source' => 'local',
            ])
            ->values()
            ->all();
    }

    /**
     * One row per distinct street name, at its **nearest** vertex.
     *
     * The bbox midpoint this used to publish is a real place for a short road
     * and a lie for a long one: «الطريق الساحلي الدولي» is ‪14‬ km of tarmac in
     * ‪14‬ rows, and the centre of any of them is nowhere the customer is. A
     * vertex is a node OpenStreetMap actually surveyed, so the pin lands on the
     * road rather than in the field beside it.
     *
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function matchingStreets(array $tokens, float $biasLat, float $biasLng): array
    {
        $query = DB::table('gazetteer_streets');
        ArabicSearch::applyNormalized($query, ['name_normalized'], $tokens);

        $best = [];

        foreach ($query->limit(self::NAME_CANDIDATES)->get(['name', 'name_normalized', 'geom']) as $row) {
            $geom = json_decode((string) $row->geom, true);

            if (! is_array($geom) || $geom === []) {
                continue;
            }

            $nearest = null;
            $distance = INF;

            foreach ($geom as $vertex) {
                $metres = Geo::metres($biasLat, $biasLng, (float) $vertex[0], (float) $vertex[1]);

                if ($metres < $distance) {
                    $distance = $metres;
                    $nearest = $vertex;
                }
            }

            if ($nearest === null) {
                continue;
            }

            $key = (string) ($row->name_normalized ?? $row->name);

            if (isset($best[$key]) && $best[$key]['distance'] <= $distance) {
                continue;
            }

            $best[$key] = [
                'distance' => $distance,
                'row' => [
                    'name' => (string) $row->name,
                    'address' => (string) $row->name,
                    'lat' => (float) $nearest[0],
                    'lng' => (float) $nearest[1],
                    'kind' => 'street',
                    'source' => 'local',
                ],
            ];
        }

        return array_values(array_map(static fn (array $entry): array => $entry['row'], $best));
    }

    /**
     * The ‪6 128‬ mapped doors, found by number, by street or by area.
     *
     * **A door is offered as a door only when its number was typed.** Every door
     * carries its street and its area in its label, so «ايزيس» matches ‪54‬ of
     * them and «الكورنيش» ‪157‬ — and a list of «مبنى ‪17‬، مبنى ‪18‬، مبنى ‪19‬» under
     * an area the customer named is noise that pushes the area itself off the
     * screen. The street and area tables already answer for those names. So:
     *
     * - a token that starts the door's number — «‪6175‬», «‪20‬ الاندلس» — offers
     *   the door, and a number typed in full beats one that merely starts with it;
     * - otherwise the door contributes its **area**, once per name, and only where
     *   the area table did not already answer. Three areas exist on doors and
     *   nowhere else — «المرحلة ‪1‬», «المرحلة ‪2‬», «جنة المنصورة الجديدة» — and
     *   without this they would not be findable at all. Every door street is
     *   already in the street table (checked against the extract, ‪61‬ of ‪61‬).
     *
     * Ranked prefix-first **in SQL**, before the candidate cap, so the rows that
     * survive it are the ones whose label starts with what was typed.
     *
     * @param  list<string>  $tokens
     * @param  list<array<string, mixed>>  $areasAnswered  rows {@see matchingAreas()} already produced
     * @return list<array<string, mixed>>
     */
    private function matchingDoors(
        array $tokens,
        string $query,
        float $biasLat,
        float $biasLng,
        string $language,
        int $limit,
        array $areasAnswered,
    ): array {
        $builder = DB::table('gazetteer_points');
        ArabicSearch::applyNormalized($builder, ['label_normalized'], $tokens);

        $rows = $builder
            ->orderBy(ArabicSearch::rankNormalized('label_normalized', $query))
            ->limit(self::POINT_CANDIDATES)
            ->get(['lat', 'lng', 'house_number', 'street', 'area']);

        $known = array_flip(array_map(
            static fn (array $row): string => ArabicSearch::normalize((string) $row['name']),
            $areasAnswered,
        ));

        $exact = [];
        $prefix = [];
        $areas = [];

        foreach ($rows as $row) {
            $number = ArabicSearch::normalize((string) $row->house_number);
            $area = self::trimmed($row->area);
            $distance = Geo::metres($biasLat, $biasLng, (float) $row->lat, (float) $row->lng);

            $typedExactly = $number !== '' && in_array($number, $tokens, true);
            $typedStart = $number !== '' && array_filter($tokens, static fn (string $t): bool => str_starts_with($number, $t)) !== [];

            if (! $typedExactly && ! $typedStart) {
                $key = $area === null ? null : ArabicSearch::normalize($area);

                // The area has to answer the query **by itself**. A door on «شارع
                // الكورنيش» tagged «منطقة آمون» matched through its street, and
                // offering «منطقة آمون» to someone who typed «الكورنيش» is a
                // confident wrong row.
                $areaAnswers = $key !== null
                    && array_filter($tokens, static fn (string $t): bool => ! str_contains($key, $t)) === [];

                if ($areaAnswers && ! isset($known[$key]) && (! isset($areas[$key]) || $areas[$key]['distance'] > $distance)) {
                    $areas[$key] = [
                        'name' => $area,
                        'address' => $area,
                        'lat' => (float) $row->lat,
                        'lng' => (float) $row->lng,
                        'kind' => 'area',
                        'source' => 'local',
                        'distance' => $distance,
                    ];
                }

                continue;
            }

            $line = $this->composeLine(self::trimmed($row->house_number), self::trimmed($row->street), $language);

            if ($line === null) {
                continue;
            }

            $door = [
                'name' => $line,
                'address' => implode('، ', array_values(array_filter([$line, $area]))),
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'kind' => 'address',
                'source' => 'local',
                'distance' => $distance,
            ];

            if ($typedExactly) {
                $exact[] = $door;
            } else {
                $prefix[] = $door;
            }
        }

        $byDistance = static fn (array $a, array $b): int => $a['distance'] <=> $b['distance'];
        $doors = $exact !== [] ? $exact : $prefix;
        usort($doors, $byDistance);
        $areas = array_values($areas);
        usort($areas, $byDistance);

        return array_map(
            static function (array $row): array {
                unset($row['distance']);

                return $row;
            },
            array_merge($areas, array_slice($doors, 0, $limit)),
        );
    }

    private function covers(float $lat, float $lng): bool
    {
        [$south, $west, $north, $east] = self::BOUNDS;

        return $lat >= $south && $lat <= $north && $lng >= $west && $lng <= $east;
    }

    /**
     * The nearest mapped door, with its distance in metres.
     *
     * @return array{row: object, distance: float}|null
     */
    private function nearestPoint(float $lat, float $lng): ?array
    {
        $rows = DB::table('gazetteer_points')
            ->whereBetween('lat', [$lat - self::POINT_BOX_DEGREES, $lat + self::POINT_BOX_DEGREES])
            ->whereBetween('lng', [$lng - self::POINT_BOX_DEGREES, $lng + self::POINT_BOX_DEGREES])
            ->get(['lat', 'lng', 'house_number', 'street', 'area', 'district', 'city']);

        $best = null;

        foreach ($rows as $row) {
            $distance = Geo::metres($lat, $lng, (float) $row->lat, (float) $row->lng);

            if ($best === null || $distance < $best['distance']) {
                $best = ['row' => $row, 'distance' => $distance];
            }
        }

        return $best;
    }

    /**
     * The nearest point on the nearest named street — perpendicular to a
     * segment, not to an endpoint and not to a centroid.
     *
     * @return array{name: string, distance: float}|null
     */
    private function nearestStreet(float $lat, float $lng): ?array
    {
        $pad = self::STREET_BOX_DEGREES;

        $rows = DB::table('gazetteer_streets')
            ->where('min_lat', '<=', $lat + $pad)
            ->where('max_lat', '>=', $lat - $pad)
            ->where('min_lng', '<=', $lng + $pad)
            ->where('max_lng', '>=', $lng - $pad)
            ->get(['name', 'geom']);

        $best = null;

        foreach ($rows as $row) {
            $geom = json_decode((string) $row->geom, true);

            if (! is_array($geom) || count($geom) < 2) {
                continue;
            }

            $distance = Geo::metresToPolyline($lat, $lng, $geom);

            if ($best === null || $distance < $best['distance']) {
                $best = ['name' => (string) $row->name, 'distance' => $distance];
            }
        }

        return $best;
    }

    /**
     * The area name — from the door's own tag when there is one, otherwise the
     * nearest named area.
     *
     * @param  array{row: object, distance: float}|null  $point
     */
    private function areaFor(?array $point, float $lat, float $lng): ?string
    {
        if ($point !== null) {
            $tagged = self::trimmed($point['row']->area);

            if ($tagged !== null) {
                return $tagged;
            }
        }

        $rows = DB::table('gazetteer_areas')
            ->whereBetween('lat', [$lat - self::AREA_BOX_DEGREES, $lat + self::AREA_BOX_DEGREES])
            ->whereBetween('lng', [$lng - self::AREA_BOX_DEGREES, $lng + self::AREA_BOX_DEGREES])
            ->get(['name', 'lat', 'lng']);

        $best = null;
        $bestDistance = self::AREA_METRES;

        foreach ($rows as $row) {
            $distance = Geo::metres($lat, $lng, (float) $row->lat, (float) $row->lng);

            // Strictly nearer than the cap, so a point with nothing within it
            // keeps `$best` null rather than taking the least-wrong answer.
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string) $row->name;
            }
        }

        return $best;
    }

    /**
     * «‪145‬ شارع إسماعيل فريد», «شارع إسماعيل فريد», «مبنى ‪6175‬» — in that order
     * of preference.
     *
     * A bare number is not an address anywhere a street has a name, but in the
     * planned districts — «منطقة ‪6أ‬», ‪4 182‬ buildings, almost no named internal
     * roads — the building number *is* how the place is addressed, so it is
     * labelled rather than dropped.
     */
    private function composeLine(?string $houseNumber, ?string $streetName, string $language): ?string
    {
        // The isolate is not decoration. An unwrapped Western number sitting
        // between Arabic words and a comma renders with the comma on the wrong
        // side, and a building number read backwards is a different building.
        $number = $houseNumber === null ? null : "\u{202A}{$houseNumber}\u{202C}";

        if ($streetName !== null) {
            return $number === null ? $streetName : "{$number} {$streetName}";
        }

        if ($number === null) {
            return null;
        }

        return (str_starts_with($language, 'en') ? 'Building ' : 'مبنى ').$number;
    }

    private static function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
