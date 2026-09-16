<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenStreetMap's own geocoder — Nominatim — with no key, no account and no bill.
 *
 * D-84. The platform ran on {@see MapboxGeocoder} or on nothing, and «nothing»
 * is what a deployment without `MAPBOX_TOKEN` got: `/geo/reverse` answered
 * `{"address": null}` for every pin, so `CUS-23`'s street line was empty and the
 * only thing the screen could name was the **zone** — which is why the operator
 * read «الشاطئ» where a street name belonged.
 *
 * It is more precise than Mapbox for this town, not less: `addressdetails=1`
 * returns the parts separately — `road`, `house_number`, `neighbourhood` — where
 * Mapbox v5 returns one `place_name` with a governorate tail that has to be cut
 * back off.
 *
 * Nominatim's usage policy is the constraint that shapes this class: identify
 * yourself, and do not send bulk traffic. The `User-Agent` is configured, and
 * every answer is cached for `maps.cache.reverse_days` — the same 4-decimal
 * (~‪11‬ m) key the Mapbox client uses, so a pin dragged across ground another
 * customer has already covered costs no request at all.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
class OsmGeocoder implements Geocoder, ReportsFailure
{
    use SortsByProximity;
    use TracksFailure;

    public function isConfigured(): bool
    {
        return (bool) config('maps.osm.geocoding', true) && filled(config('maps.osm.nominatim'));
    }

    public function reverse(float $lat, float $lng, string $language = 'ar'): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $key = sprintf('geo:osm:rev:%s:%.4f,%.4f', $language, $lat, $lng);

        return Cache::remember($key, now()->addDays((int) config('maps.cache.reverse_days', 30)), function () use ($lat, $lng, $language) {
            $row = $this->call('/reverse', [
                'lat' => $lat,
                'lon' => $lng,
                // 18 is «building / house number». Lower numbers answer with the
                // district, which is the thing the customer complained about
                // already being all they were shown.
                'zoom' => 18,
                'addressdetails' => 1,
                'accept-language' => $language,
            ]);

            if (! is_array($row) || $row === []) {
                return null;
            }

            return self::compose($row, $language);
        });
    }

    public function search(
        string $query,
        ?float $lat = null,
        ?float $lng = null,
        string $language = 'ar',
        ?string $country = null,
        int $limit = 10,
    ): array {
        $this->callFailed = false;
        $query = trim($query);

        if (! $this->isConfigured() || mb_strlen($query) < 2) {
            return [];
        }

        $centre = config('maps.default_center');
        $biasLat = $lat ?? (float) $centre['lat'];
        $biasLng = $lng ?? (float) $centre['lng'];
        $country = $country ?: (string) config('maps.country', 'eg');

        $key = sprintf(
            'geo:osm:fwd:%s:%s:%s:%.2f,%.2f',
            $language,
            $country,
            md5(mb_strtolower($query)),
            $biasLat,
            $biasLng,
        );

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->call('/search', [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => min(10, max(5, $limit)),
            'countrycodes' => $country,
            'accept-language' => $language,
            // A box roughly ‪25‬ km on a side around where the customer is
            // standing, un-bounded: results inside it come first, and
            // «الكورنيش» still finds Alexandria's if this town has none.
            'viewbox' => sprintf('%F,%F,%F,%F', $biasLng - 0.25, $biasLat + 0.25, $biasLng + 0.25, $biasLat - 0.25),
        ]);

        // A ‪403‬ for a missing `User-Agent` reads exactly like «no results», and
        // caching it for a week would make an afternoon's misconfiguration
        // outlive the fix.
        if ($this->callFailed || ! is_array($rows)) {
            return [];
        }

        $mapped = collect($rows)
            ->map(function (array $row) use ($language) {
                $composed = self::compose($row, $language);

                return [
                    'name' => $composed['place'] ?? $composed['street'] ?? (string) ($row['name'] ?? ''),
                    'address' => $composed['address'],
                    'lat' => (float) ($row['lat'] ?? 0),
                    'lng' => (float) ($row['lon'] ?? 0),
                    'kind' => self::kindOf($row, $composed),
                    'source' => 'osm',
                ];
            })
            ->filter(fn (array $row) => $row['lat'] !== 0.0 || $row['lng'] !== 0.0)
            ->values()
            ->all();

        // The viewbox above is un-bounded on purpose — it prefers, it does
        // not restrict — so Nominatim can still rank a prominent match far
        // outside it first. See {@see SortsByProximity}.
        $mapped = self::nearestFirst($mapped, $biasLat, $biasLng);

        Cache::put($key, $mapped, now()->addDays((int) config('maps.cache.search_days', 7)));

        return $mapped;
    }

    /**
     * Nominatim's `addresstype` / `class` -> the row's icon.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $composed
     */
    private static function kindOf(array $row, array $composed): string
    {
        $type = (string) ($row['addresstype'] ?? $row['type'] ?? '');

        return match (true) {
            in_array($type, ['city', 'town', 'village', 'municipality', 'hamlet'], true) => 'city',
            in_array($type, ['neighbourhood', 'suburb', 'quarter', 'city_district', 'residential'], true) => 'area',
            in_array($type, ['road', 'street', 'pedestrian', 'footway'], true) => 'street',
            $type === 'house' || ($composed['house_number'] ?? null) !== null => 'address',
            ($composed['place'] ?? null) !== null => 'poi',
            ($composed['street'] ?? null) !== null => 'street',
            default => 'poi',
        };
    }

    /**
     * One Nominatim row -> the shape {@see Geocoder} promises.
     *
     * The composition is the point of the whole class: «‪12‬ شارع البحر، الكورنيش»
     * is an address a captain can ride to, and `display_name` — «12, شارع البحر,
     * الكورنيش, جمصة, مركز جمصة, الدقهلية, 35511, مصر» — is not.
     *
     * @param  array<string, mixed>  $row
     * @return array{address: string, place: ?string, street: ?string, house_number: ?string, neighborhood: ?string, city: ?string}
     */
    private static function compose(array $row, string $language): array
    {
        /** @var array<string, string> $address */
        $address = is_array($row['address'] ?? null) ? $row['address'] : [];

        $street = self::first($address, ['road', 'pedestrian', 'footway', 'residential']);
        $houseNumber = self::first($address, ['house_number']);
        $neighborhood = self::first($address, ['neighbourhood', 'suburb', 'quarter', 'city_district', 'hamlet']);
        $city = self::first($address, ['city', 'town', 'village', 'municipality', 'county']);

        // A POI's own name — «مسجد الرحمة», «كافيه الكورنيش». Nominatim puts it
        // in `name`, and it is the most useful half of the answer whenever the
        // pin is on a building rather than on tarmac. `display_name` repeats it
        // as its first comma-separated part, so it is never taken from there.
        $name = self::trimmed($row['name'] ?? null);

        // A house number without a street is «‪1479‬» — a number the reader
        // cannot place, and Nominatim answers exactly that on the blocks of
        // Gamasa where the buildings are mapped and the roads are not.
        $line = $street === null
            ? null
            : trim(($houseNumber === null ? '' : "\u{202A}{$houseNumber}\u{202C} ").$street);

        $parts = array_values(array_unique(array_filter([
            $name !== null && $name !== $street ? $name : null,
            $line,
            $neighborhood,
        ])));

        $composed = implode('، ', $parts);

        if ($composed === '') {
            // Every part came back empty — open desert, or a language the
            // dataset has no names in. The display line is still better than
            // nothing, minus the postcode-and-country tail nobody reads.
            $display = (string) ($row['display_name'] ?? '');
            $composed = trim(implode('، ', array_slice(array_map('trim', explode(',', $display)), 0, 3)));
        }

        return [
            'address' => $composed,
            'place' => $name,
            'street' => $street,
            'house_number' => $houseNumber,
            'neighborhood' => $neighborhood,
            'city' => $city,
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  list<string>  $keys
     */
    private static function first(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::trimmed($address[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>|null
     */
    private function call(string $path, array $query): ?array
    {
        try {
            $response = Http::timeout((int) config('maps.timeout_seconds', 6))
                ->retry(1, 200)
                // Required by the usage policy. Without it Nominatim answers
                // 403 to everything, which would look exactly like «no provider
                // configured» and be diagnosed as that for an afternoon.
                ->withHeaders(['User-Agent' => (string) config('maps.osm.user_agent')])
                ->get(rtrim((string) config('maps.osm.nominatim'), '/').$path, $query + ['format' => 'jsonv2']);

            if ($response->failed()) {
                Log::warning('[OSM] geocoding failed', ['status' => $response->status(), 'path' => $path]);
                $this->callFailed = true;

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            // Never rethrow. The caller's contract is «a label or nothing» —
            // and, since D-127, which of the two this was.
            Log::warning('[OSM] geocoding threw', ['message' => $e->getMessage()]);
            $this->callFailed = true;

            return null;
        }
    }
}
