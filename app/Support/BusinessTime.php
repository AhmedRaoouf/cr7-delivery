<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The wall clock the business runs on, which is not the one the database runs on.
 *
 * Rows are stored in UTC and must stay that way — a mixed-timezone table is unfixable
 * once it exists. But «يفتح ‪10:00‬ ويغلق ‪02:00‬» is a statement about a clock on a wall in
 * Gamasa, and comparing it against `now()` compared it against UTC: the production server
 * runs UTC, Egypt is three hours ahead, and every restaurant in the platform read as
 * closed from 04:00 to 12:00 local, every day. The dashboard's switch wrote
 * `is_open_manual = true`, the API answered 200, and the header still said «المطعم مغلق»
 * because `is_open_now` is that flag **and** the window — which had not opened yet as far
 * as UTC was concerned.
 *
 * `business_date` is the same kind of value: the calendar day a shift belongs to, as the
 * people working it would name it.
 *
 * Deliberately a config value rather than a `settings` row: it is not an operator
 * decision, it is where the company is, and a wrong one here breaks opening hours
 * everywhere at once.
 */
final class BusinessTime
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Africa/Cairo');
    }

    /** Now, on that wall clock. */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /** The local calendar day — what `business_date` means. */
    public static function date(): string
    {
        return self::now()->toDateString();
    }
}
