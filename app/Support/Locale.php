<?php

namespace App\Support;

use Illuminate\Http\Request;

/** doc 08 §1 — Accept-Language drives every localized string in the response. */
class Locale
{
    public static function current(Request $request): string
    {
        $header = strtolower((string) $request->header('Accept-Language', 'ar'));

        return str_starts_with($header, 'en') ? 'en' : 'ar';
    }
}
