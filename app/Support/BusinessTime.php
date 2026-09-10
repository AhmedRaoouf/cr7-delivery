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

    // ------------------------------------------------------------ boundaries

    /**
     * The other half of the same idea: «اليوم» is a question about that wall
     * clock too, and every answer below is returned in **UTC**, ready for a
     * `where` clause. Only the boundary is computed in Cairo.
     *
     * `Carbon::today()` in a UTC process answers 22:00 the previous evening
     * there, so `ADM-04`'s «اليوم» counted from two hours after Cairo midnight,
     * the report ranges lost their first two hours at one end and picked up two
     * of the previous day at the other, and the captain's «اليوم» chip on
     * `DRV-27` did the same.
     */
    public static function startOfToday(): Carbon
    {
        return self::now()->startOfDay()->utc();
    }

    public static function startOfDaysAgo(int $days): Carbon
    {
        return self::now()->subDays($days)->startOfDay()->utc();
    }

    public static function endOfToday(): Carbon
    {
        return self::now()->endOfDay()->utc();
    }

    /**
     * Re-read a date an operator typed as a date on that wall clock.
     *
     * `$request->date('filter.date_from')` parses «2026-09-05» at **UTC**
     * midnight, which is 02:00 in Cairo — so a range given as one calendar day
     * silently dropped its first two hours and picked up two from the day
     * before at the far end.
     *
     * @param  bool  $endOfDay  Whether the date names the close of a range.
     */
    public static function fromInput(?Carbon $date, bool $endOfDay = false): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        $local = Carbon::create(
            $date->year,
            $date->month,
            $date->day,
            $date->hour,
            $date->minute,
            $date->second,
            self::timezone(),
        );

        // A bare date carries no time, and the two ends of a range want opposite
        // halves of it.
        if ($date->hour === 0 && $date->minute === 0 && $date->second === 0) {
            $local = $endOfDay ? $local->endOfDay() : $local->startOfDay();
        }

        return $local->utc();
    }

    /**
     * The local calendar day a boundary belongs to — what the operator typed.
     *
     * Every boundary above is returned in **UTC**, ready for a `where` clause, and Cairo
     * is two hours ahead of it: `fromInput('2026-01-05')` is `2026-01-04 22:00Z`. Printing
     * that with a bare `format('Y-m-d')` is how «الفترة» at the top of every exported
     * workbook came out a day earlier than the range the operator asked for, and a day
     * earlier than the same period printed on the PDF beside it.
     */
    public static function localDate(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(self::timezone())->toDateString();
    }

    /** The same wall clock as [localDate], for a column that prints the hour. */
    public static function localTime(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(self::timezone())->format('H:i');
    }

    /**
     * A SQL expression that reads `$column` on that wall clock.
     *
     * `DATE(created_at)` and `HOUR(created_at)` are UTC, so the sales chart cut
     * its days two hours late and «ساعات الذروة» pointed at 20:00 for a rush the
     * restaurants lived through at 22:00. `CONVERT_TZ` would be the exact answer
     * and needs MySQL's named-zone tables, which shared hosting usually does not
     * load — so the current offset is applied instead. A range that straddles
     * the October change is off by an hour on one side of it; every other range,
     * which is all of them in practice, is exact.
     */
    public static function sqlLocal(string $column): string
    {
        $minutes = (int) self::now()->utcOffset();

        return sprintf('DATE_ADD(%s, INTERVAL %d MINUTE)', $column, $minutes);
    }
}
