<?php

namespace App\Enums;

/**
 * What a library picture may be used for (doc 71 §2). A picker is asked for one field and
 * shows only the assets whose `usage_types` name that field's usage.
 *
 * `fallback` is not a field: it marks the picture the platform shows where a product,
 * category or cover has none, and no picker offers it on its own.
 */
enum MediaUsage: string
{
    case Product = 'product';
    case Category = 'category';
    case Cover = 'cover';
    case Logo = 'logo';
    case Banner = 'banner';
    case Icon = 'icon';
    case Fallback = 'fallback';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $u) => $u->value, self::cases());
    }
}
