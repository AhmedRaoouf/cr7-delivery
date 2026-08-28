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
class MapboxGeocoder implements Geocoder
{
    private const ENDPOINT = 'https://api.mapbox.com/geocoding/v5/mapbox.places';

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
                'types' => 'address,poi,neighborhood,locality,place',
                'limit' => 1,
            ]);

            $first = $features[0] ?? null;
            if (! $first) {
                return null;
            }

            return [
                'address' => (string) ($first['place_name'] ?? ''),
                'place' => $this->context($first, 'poi') ?? ($first['text'] ?? null),
                'neighborhood' => $this->context($first, 'neighborhood'),
                'city' => $this->context($first, 'place') ?? $this->context($first, 'locality'),
            ];
        });
    }

    public function search(string $query, ?float $lat = null, ?float $lng = null, string $language = 'ar'): array
    {
        $query = trim($query);
        if (! $this->isConfigured() || mb_strlen($query) < 2) {
            return [];
        }

        $centre = config('maps.default_center');
        $proximityLat = $lat ?? (float) $centre['lat'];
        $proximityLng = $lng ?? (float) $centre['lng'];

        $key = sprintf('geo:fwd:%s:%s:%.2f,%.2f', $language, md5(mb_strtolower($query)), $proximityLat, $proximityLng);

        return Cache::remember($key, now()->addDays((int) config('maps.cache.search_days', 7)), function () use ($query, $proximityLat, $proximityLng, $language) {
            $features = $this->call(rawurlencode($query), [
                'language' => $language,
                // Bias to where the customer is standing. Without it "الكورنيش"
                // returns Alexandria's before جمصة's, every time.
                'proximity' => "{$proximityLng},{$proximityLat}",
                'country' => config('maps.country', 'eg'),
                'types' => 'address,poi,neighborhood,locality,place',
                'limit' => 6,
            ]);

            return collect($features)
                ->map(fn (array $feature) => [
                    'name' => (string) ($feature['text'] ?? ''),
                    'address' => (string) ($feature['place_name'] ?? ''),
                    'lat' => (float) ($feature['center'][1] ?? 0),
                    'lng' => (float) ($feature['center'][0] ?? 0),
                ])
                ->filter(fn (array $row) => $row['lat'] !== 0.0 || $row['lng'] !== 0.0)
                ->values()
                ->all();
        });
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

                return [];
            }

            return $response->json('features') ?? [];
        } catch (Throwable $e) {
            // Never rethrow. The caller's contract is "a label or nothing".
            Log::warning('[MAPBOX] geocoding threw', ['message' => $e->getMessage()]);

            return [];
        }
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
