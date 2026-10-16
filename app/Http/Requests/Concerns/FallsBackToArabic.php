<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Http\Request;

/**
 * An English field left empty reads as the Arabic one.
 *
 * The web dashboards have one input per piece of copy, so `name_en` / `title_en` arrive
 * blank (or not at all) and the API answered 422 «حقل name_en مطلوب». The columns are
 * NOT NULL, and the apps already show the Arabic side when there is no English, so the
 * honest default is to store the Arabic text.
 *
 * Only a **create** (or an update that names the English field blank) is filled: an
 * update that does not mention the English field leaves the stored English untouched.
 * The rules stay `required`, so a request with neither language still fails.
 */
trait FallsBackToArabic
{
    /** @param  array<string, string>  $pairs  english key => arabic key */
    protected function fillEnglishFromArabic(Request $request, array $pairs, bool $creating): void
    {
        foreach ($pairs as $en => $ar) {
            if ($request->filled($en) || ! $request->filled($ar)) {
                continue;
            }

            if ($creating || $request->has($en)) {
                $request->merge([$en => $request->input($ar)]);
            }
        }
    }
}
