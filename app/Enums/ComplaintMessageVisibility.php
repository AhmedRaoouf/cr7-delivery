<?php

namespace App\Enums;

/** `internal` = admin-only note inside the thread — never shown to the restaurant/customer. */
enum ComplaintMessageVisibility: string
{
    case All = 'all';
    case Internal = 'internal';
}
