<?php

namespace App\Services\Maps;

/**
 * A source that can be *down* as distinct from *empty*.
 *
 * Both {@see MapboxGeocoder} and {@see OsmGeocoder} log a failed call and return
 * `[]`, so every caller above them has read a refused query, an expired token, a
 * timeout and a Nominatim ‪403‬ for a missing `User-Agent` as «nothing matched».
 * The screen then says «لا توجد نتائج» — which is a sentence the customer
 * believes, retypes around, and eventually gives up on.
 *
 * The distinction costs one flag and changes what the customer is told:
 * «تعذّر البحث، حاول مرة أخرى» when everything asked for an answer failed, and
 * «لا توجد نتائج» only when something actually answered and had nothing.
 *
 * {@see ChainGeocoder} implements it too, for the whole chain at once.
 */
interface ReportsFailure
{
    /**
     * Whether the last `search()` on this source could not be answered because the
     * call itself failed, rather than because nothing matched.
     *
     * False after a call that was served from cache, and false after a call that
     * succeeded and returned no rows.
     */
    public function lastCallFailed(): bool;
}
