<?php

namespace App\Enums;

/** FR-MENU-03 — `pager` intended for restaurants with more than 8 categories. */
enum MenuLayout: string
{
    case ListLayout = 'list';
    case Pager = 'pager';
}
