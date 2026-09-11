<?php

namespace App\Services\Maps;

use App\Support\Geo;
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
    use SortsByProximity;

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

    public function isConfigured(): bool
    {
        // One count, cached for the process and then for an hour: this is asked
        // on every reverse lookup and the answer changes only on a re-seed.
        return Cache::remember(
            'geo:gazetteer:ready',
            now()->addHour(),
            static fn (): bool => DB::table('gazetteer_points')->exists(),
        );
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

    public function search(string $query, ?float $lat = null, ?float $lng = null, string $language = 'ar'): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2 || ! $this->isConfigured()) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';

        $streets = DB::table('gazetteer_streets')
            ->where('name', 'like', $like)
            ->limit(8)
            ->get(['name', 'min_lat', 'max_lat', 'min_lng', 'max_lng'])
            ->map(static fn (object $row): array => [
                'name' => $row->name,
                'address' => $row->name,
                // The bbox centre is a fine *destination* for a street the
                // customer typed by name; it is only as a **label** for a pin
                // that a centroid lies, which is why reverse() never uses one.
                'lat' => ((float) $row->min_lat + (float) $row->max_lat) / 2,
                'lng' => ((float) $row->min_lng + (float) $row->max_lng) / 2,
            ]);

        $areas = DB::table('gazetteer_areas')
            ->where('name', 'like', $like)
            ->limit(8)
            ->get(['name', 'lat', 'lng', 'city'])
            ->map(static fn (object $row): array => [
                'name' => $row->name,
                'address' => trim($row->name.($row->city === null ? '' : '، '.$row->city), '، '),
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
            ]);

        $rows = $areas->concat($streets)->values()->all();

        if ($rows === []) {
            return [];
        }

        $centre = config('maps.default_center');

        return self::nearestFirst($rows, $lat ?? (float) $centre['lat'], $lng ?? (float) $centre['lng']);
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

        $rows = DB::table('gazetteer_areas')->get(['name', 'lat', 'lng']);

        $best = null;
        $bestDistance = INF;

        foreach ($rows as $row) {
            $distance = Geo::metres($lat, $lng, (float) $row->lat, (float) $row->lng);

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
