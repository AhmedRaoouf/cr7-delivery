<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mapbox Geocoding v5, proxied.
 *
 * Proxied for three reasons, in order of how much they cost: the token stays
 * off the device, the answers are cached so a dragged pin does not spend a
 * billed request every metre, and a provider outage becomes a missing label
 * rather than a broken screen.
 *
 * @see https://docs.mapbox.com/api/search/geocoding-v5/
 */
class MapboxGeocoder implements Geocoder, ReportsFailure
{
    use SortsByProximity;
    use TracksFailure;

    private const ENDPOINT = 'https://api.mapbox.com/geocoding/v5/mapbox.places';

    /**
     * The feature types a forward search asks for.
     *
     * `region` and `district` were missing, and they are what a governorate and
     * a markaz come back as: «الدقهلية» and «مركز جمصة» answered nothing at all
     * while every one of their streets answered. `postcode` is left out on
     * purpose — nobody in this town has ever given one as an address.
     *
     * The **reverse** list is deliberately not this one and must not be widened
     * to match it: Mapbox refuses a reverse lookup carrying `limit` beside more
     * than one type, and that pair is what answered `null` for every pin on
     * 2026-09-11. See {@see reverse()}.
     */
    private const SEARCH_TYPES = 'address,poi,neighborhood,locality,place,district,region';

    public function isConfigured(): bool
    {
        return filled(config('maps.mapbox.token'));
    }

    public function reverse(float $lat, float $lng, string $language = 'ar'): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // 4 decimals is ~11 m. Anything finer caches nothing, because no two
        // frames of a moving map ever share a key.
        $key = sprintf('geo:rev:%s:%.4f,%.4f', $language, $lat, $lng);

        return Cache::remember($key, now()->addDays((int) config('maps.cache.reverse_days', 30)), function () use ($lat, $lng, $language) {
            $features = $this->call("{$lng},{$lat}", [
                'language' => $language,
                // Most specific first, and more than one of them: a pin on a
                // building answers `poi` **and** `address`, and the street name
                // lives on the second. That is the default for a reverse lookup
                // — one feature per requested type — so there is nothing to ask
                // for beyond the types themselves.
                //
                // **Never send `limit` here.** Mapbox rejects it on a reverse
                // lookup unless it comes with a *single* `types` value:
                // «limit must be combined with a single type parameter when
                // reverse geocoding», HTTP 422. A `limit=5` alongside these five
                // types shipped to production on 2026-09-11 and answered `null`
                // for every pin — the exact symptom D-84 was written to fix,
                // reintroduced by the commit that fixed it. The test suite
                // mocked the response and never saw the request.
                'types' => 'address,poi,neighborhood,locality,place',
            ]);

            $first = $features[0] ?? null;
            if (! $first) {
                return null;
            }

            $streetFeature = self::ofType($features, 'address') ?? $first;
            $street = self::isType($streetFeature, 'address') ? ($streetFeature['text'] ?? null) : null;
            // On an `address` feature Mapbox puts the house number in
            // `address` and the street in `text` — the opposite way round from
            // every other feature type it returns.
            $houseNumber = self::isType($streetFeature, 'address') ? ($streetFeature['address'] ?? null) : null;
            $poi = self::ofType($features, 'poi');
            $neighborhood = $this->context($first, 'neighborhood') ?? ($this->firstTextOfType($features, 'neighborhood'));

            $line = trim(implode(' ', array_filter([
                $houseNumber === null ? null : "\u{202A}{$houseNumber}\u{202C}",
                $street,
            ])));

            $parts = array_values(array_unique(array_filter([
                // The POI, the street, or — for a feature typed as something
                // this code does not special-case — whatever Mapbox called it.
                // Dropping that last case cost the street name on every answer
                // whose `place_type` was absent.
                $poi['text'] ?? null,
                $line !== '' ? $line : ($street ?? ($first['text'] ?? null)),
                $neighborhood,
            ])));

            return [
                // The composed line, not `place_name`: «شارع البحر، الكورنيش»
                // reads, and «شارع البحر، جمصة، الدقهلية، مصر» wraps to three
                // lines and says nothing extra.
                'address' => $parts === [] ? (string) ($first['place_name'] ?? '') : implode('، ', $parts),
                'place' => $poi['text'] ?? ($first['text'] ?? null),
                'street' => $street,
                'house_number' => is_string($houseNumber) ? $houseNumber : null,
                'neighborhood' => $neighborhood,
                'city' => $this->context($first, 'place') ?? $this->context($first, 'locality'),
            ];
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
        $proximityLat = $lat ?? (float) $centre['lat'];
        $proximityLng = $lng ?? (float) $centre['lng'];
        $country = $country ?: (string) config('maps.country', 'eg');

        $key = sprintf(
            'geo:fwd:%s:%s:%s:%.2f,%.2f',
            $language,
            $country,
            md5(mb_strtolower($query)),
            $proximityLat,
            $proximityLng,
        );

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $features = $this->call(rawurlencode($query), [
            'language' => $language,
            // Bias to where the customer is standing. Without it "الكورنيش"
            // returns Alexandria's before جمصة's, every time.
            'proximity' => "{$proximityLng},{$proximityLat}",
            'country' => $country,
            'types' => self::SEARCH_TYPES,
            // One more than the caller can use: the merge above drops duplicates
            // of rows our own tables already hold, so a list cut to exactly the
            // limit can arrive short.
            'limit' => min(10, max(5, $limit)),
        ]);

        // A refused query, an expired token, a timeout. Caching the `[]` that
        // comes back would turn a ten-second outage into a week of «لا توجد
        // نتائج» for every customer who typed that word during it.
        if ($this->callFailed) {
            return [];
        }

        $rows = collect($features)
            ->map(fn (array $feature) => [
                'name' => (string) ($feature['text'] ?? ''),
                'address' => (string) ($feature['place_name'] ?? ''),
                'lat' => (float) ($feature['center'][1] ?? 0),
                'lng' => (float) ($feature['center'][0] ?? 0),
                'kind' => self::kindOf($feature),
                'source' => 'mapbox',
            ])
            ->filter(fn (array $row) => $row['lat'] !== 0.0 || $row['lng'] !== 0.0)
            ->values()
            ->all();

        // `proximity` is a hint Mapbox lets name prominence outrank, so the
        // street the customer is standing on can land third behind two in
        // other governorates. See {@see SortsByProximity}.
        $rows = self::nearestFirst($rows, $proximityLat, $proximityLng);

        Cache::put($key, $rows, now()->addDays((int) config('maps.cache.search_days', 7)));

        return $rows;
    }

    /**
     * Mapbox's `place_type` -> the row's icon.
     *
     * @param  array<string, mixed>  $feature
     */
    private static function kindOf(array $feature): string
    {
        foreach ((array) ($feature['place_type'] ?? []) as $type) {
            $kind = match ($type) {
                'poi' => 'poi',
                'address' => 'address',
                'neighborhood', 'locality', 'postcode' => 'area',
                'place', 'district', 'region', 'country' => 'city',
                default => null,
            };

            if ($kind !== null) {
                return $kind;
            }
        }

        return 'poi';
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function call(string $subject, array $query): array
    {
        try {
            $response = Http::timeout((int) config('maps.timeout_seconds', 6))
                ->retry(1, 200)
                ->get(self::ENDPOINT."/{$subject}.json", $query + [
                    'access_token' => config('maps.mapbox.token'),
                ]);

            if ($response->failed()) {
                Log::warning('[MAPBOX] geocoding failed', ['status' => $response->status(), 'body' => $response->body()]);
                $this->callFailed = true;

                return [];
            }

            return $response->json('features') ?? [];
        } catch (Throwable $e) {
            // Never rethrow. The caller's contract is "a label or nothing" —
            // but the caller is also told *which* of the two it got, so a dead
            // provider does not read to the customer as an empty town.
            Log::warning('[MAPBOX] geocoding threw', ['message' => $e->getMessage()]);
            $this->callFailed = true;

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return array<string, mixed>|null
     */
    private static function ofType(array $features, string $type): ?array
    {
        foreach ($features as $feature) {
            if (self::isType($feature, $type)) {
                return $feature;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $feature */
    private static function isType(array $feature, string $type): bool
    {
        return in_array($type, (array) ($feature['place_type'] ?? []), true);
    }

    /** @param list<array<string, mixed>> $features */
    private function firstTextOfType(array $features, string $type): ?string
    {
        $feature = self::ofType($features, $type);
        $text = $feature['text'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /** @param array<string, mixed> $feature */
    private function context(array $feature, string $type): ?string
    {
        foreach (($feature['context'] ?? []) as $entry) {
            if (str_starts_with((string) ($entry['id'] ?? ''), $type.'.')) {
                return (string) ($entry['text'] ?? '') ?: null;
            }
        }

        return null;
    }
}
