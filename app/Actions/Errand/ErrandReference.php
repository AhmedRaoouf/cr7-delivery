<?php

namespace App\Actions\Errand;

/**
 * `ERR-1000`, `ERR-1001`, … — sequential, and visibly not an order number.
 *
 * Same derivation as `OrderNumber`: the row id offset so the first one reads as a
 * four-digit number rather than `ERR-1`. The `ERR-` prefix matters on the phone — an
 * operator hearing «ألف وثلاثة وأربعين» needs to know whether to open the order board or
 * the errand board.
 */
class ErrandReference
{
    private const PREFIX = 'ERR-';

    private const OFFSET = 999;

    public static function forId(int $id): string
    {
        return self::PREFIX.(self::OFFSET + $id);
    }
}
