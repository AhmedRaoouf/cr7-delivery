<?php

namespace App\Services\Maps;

use App\Models\Restaurant;
use App\Support\ArabicSearch;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * The places this platform actually delivers from, asked first.
 *
 * `GET /geo/places` — «every shop near a point, by name» — has been in the API
 * since 2026-09-07 and had **zero callers** in any of the three repos when D-127
 * was written. Meanwhile a customer typing «سندوتش عمر» into the address search
 * was asking Mapbox and Nominatim about a Gamasa sandwich shop, which is the one
 * question no tile provider will ever answer for this town: OpenStreetMap knows
 * the roads here far better than it knows which unit of a parade is a pharmacy.
 *
 * So the catalogue is a geocoding source now, and it is the **first** one. A row
 * it produces carries `place_id`, which is a `restaurants.id`: the client can
 * open the shop rather than merely drop a pin where it is.
 *
 * No network, no key, no quota — one indexed query against rows we already own,
 * behind the same cache as everything else in the chain.
 */
class PlatformPlaceGeocoder implements Geocoder
{
    /**
     * Enough to survive the merge without crowding out the streets.
     *
     * The list a customer is typing into is an *address* picker. A shop is the
     * best answer when its name is what was typed and a poor one otherwise, so
     * the ceiling is low and {@see PlaceResults} decides the rest.
     */
    private const CANDIDATES = 12;

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Nothing. A shop's name is not what a pin is standing on.
     *
     * Reverse geocoding asks «what is this point called», and the honest answer
     * for a point in front of a shop is the street it is on — the shop's own
     * name would put «سندوتش عمر» on the address of the flat above it. The chain
     * skips a null and asks the next source.
     */
    public function reverse(float $lat, float $lng, string $language = 'ar'): ?array
    {
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
        $tokens = ArabicSearch::tokens($query);

        if ($tokens === [] || mb_strlen(trim($query)) < 2) {
            return [];
        }

        $centre = config('maps.default_center');
        $biasLat = $lat ?? (float) $centre['lat'];
        $biasLng = $lng ?? (float) $centre['lng'];

        $key = sprintf(
            'geo:places:fwd:%s:%s:%.2f,%.2f',
            $language,
            md5(implode(' ', $tokens)),
            $biasLat,
            $biasLng,
        );

        return Cache::remember(
            $key,
            // Shorter than a provider's week: a shop can be renamed, moved or
            // deactivated from the dashboard this afternoon, and a geocoding
            // answer that outlives that sends a captain to an empty unit.
            now()->addMinutes((int) config('maps.cache.places_minutes', 30)),
            fn (): array => $this->matching($tokens, $query, $biasLat, $biasLng, $language),
        );
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function matching(array $tokens, string $query, float $biasLat, float $biasLng, string $language): array
    {
        $locale = str_starts_with(strtolower($language), 'en') ? 'en' : 'ar';

        $builder = Restaurant::query()
            ->select(['id', 'slug', 'name_ar', 'name_en', 'address', 'latitude', 'longitude'])
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        ArabicSearch::apply($builder, ['name_ar', 'name_en'], $tokens);

        $rows = $builder
            ->orderBy(ArabicSearch::rank('name_ar', $query))
            ->limit(self::CANDIDATES)
            ->get()
            ->map(static function (Restaurant $shop) use ($locale): array {
                $name = (string) ($shop->{"name_{$locale}"} ?? $shop->name_ar);
                // One `address` column, not a pair: it is free text the operator
                // typed, in whatever language they typed it.
                $address = trim((string) ($shop->address ?? ''));

                return [
                    'name' => $name,
                    'address' => $address !== '' ? $address : $name,
                    'lat' => (float) $shop->latitude,
                    'lng' => (float) $shop->longitude,
                    'kind' => 'place',
                    'source' => 'places',
                    'place_id' => (int) $shop->id,
                ];
            })
            ->values()
            ->all();

        usort($rows, static fn (array $a, array $b): int => Geo::metres($biasLat, $biasLng, $a['lat'], $a['lng'])
            <=> Geo::metres($biasLat, $biasLng, $b['lat'], $b['lng']));

        return $rows;
    }
}
