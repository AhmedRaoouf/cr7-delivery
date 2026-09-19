<?php

namespace App\Enums;

/**
 * `global` — every place sees it. `restricted` — only the places named by the asset's
 * audiences (a place, a place group, a store category). `hidden` — Admin only: kept in
 * the library, offered to no place.
 */
enum MediaVisibility: string
{
    case Global = 'global';
    case Restricted = 'restricted';
    case Hidden = 'hidden';
}
