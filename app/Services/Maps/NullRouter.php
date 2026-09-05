<?php

namespace App\Services\Maps;

/** No token, no roads. The caller falls back to the straight line. */
class NullRouter implements Router
{
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
