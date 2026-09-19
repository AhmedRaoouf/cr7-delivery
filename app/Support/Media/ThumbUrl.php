<?php

namespace App\Support\Media;

/**
 * The thumbnail URL of a stored picture. Filled in properly by step 3 of batch 72; until
 * then a picture is its own thumbnail.
 */
final class ThumbUrl
{
    public static function for(string $url): string
    {
        return $url;
    }
}
