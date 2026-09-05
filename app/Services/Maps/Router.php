<?php

namespace App\Services\Maps;

interface Router
{
    /**
     * Two points -> the road between them.
     *
     * Returns null when no provider is configured or the provider fails. The
     * caller draws the straight line in that case, which is what every map in
     * this application drew before this interface existed — a fallback, never
     * the intended picture.
     *
     * `geometry` is `[[lat, lng], …]` in the order the vehicle drives them.
     * Mapbox answers in `[lng, lat]`; the swap happens here so no client ever
     * has to know that.
     *
     * @return array{geometry: list<array{0: float, 1: float}>, distance_km: float, duration_minutes: float}|null
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array;

    public function isConfigured(): bool;
}
