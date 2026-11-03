<?php

namespace App\Support\Catalog;

use App\Models\Restaurant;
use App\Support\ClientVersion;
use App\Support\SettingsCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * doc 99 §5–§6 — D-173 / D-174: the rating a customer sees beside a place's name.
 *
 * `{value, count, source, is_new, welcome_until}`:
 *
 * - **`welcome`** — the place was first published less than `catalog.welcome_rating_days`
 *   ago **and** has fewer than `catalog.welcome_rating_count` ratings: value 5.0, count the
 *   real count. Nothing is written: no rating row, and `rating_avg` stays the true average.
 * - **`real`** — the true average of the ratings an operator has not hidden.
 * - **`none`** — no ratings yet: value null («بدون تقييمات»), never 0.0.
 *
 * `is_new` («جديد») is the day window alone — a place that reached its twenty ratings on
 * day three is still new on day four, it just shows its real average.
 *
 * The **shown** value is what «الأعلى تقييمًا» and `min_rating` read
 * ({@see self::orderByShown()}, {@see self::whereShownAtLeast()}). For a place whose shown
 * value is its real one, every sort orders exactly as it did before.
 *
 * A 1.0.23 app reads only `rating_avg`, so it is sent 5.0 there during the welcome
 * ({@see self::ratingAvgFor()}) — and `PublicCache` keys that body apart.
 */
final class RatingBadge
{
    public const WELCOME_VALUE = 5.0;

    public static function enabled(): bool
    {
        return SettingsCatalog::bool('catalog.welcome_rating_enabled');
    }

    public static function days(): int
    {
        return max(0, SettingsCatalog::int('catalog.welcome_rating_days'));
    }

    public static function count(): int
    {
        return max(0, SettingsCatalog::int('catalog.welcome_rating_count'));
    }

    /** @return array{value: float|null, count: int, source: string, is_new: bool, welcome_until: Carbon|null}|null */
    public static function for(?Restaurant $restaurant): ?array
    {
        if ($restaurant === null) {
            return null;
        }

        $count = (int) $restaurant->rating_count;
        $welcome = self::isWelcome($restaurant);

        if ($welcome) {
            return [
                'value' => self::WELCOME_VALUE,
                'count' => $count,
                'source' => 'welcome',
                'is_new' => true,
                'welcome_until' => self::newUntil($restaurant),
            ];
        }

        return [
            'value' => $count > 0 ? round((float) $restaurant->rating_avg, 2) : null,
            'count' => $count,
            'source' => $count > 0 ? 'real' : 'none',
            'is_new' => self::isNew($restaurant),
            'welcome_until' => null,
        ];
    }

    /** The day window since the place was first published (null when it never was). */
    public static function newUntil(Restaurant $restaurant): ?Carbon
    {
        $published = $restaurant->first_published_at;

        if ($published === null || self::days() === 0) {
            return null;
        }

        return Carbon::parse($published)->addDays(self::days());
    }

    public static function isNew(Restaurant $restaurant): bool
    {
        $until = self::newUntil($restaurant);

        return $until !== null && now()->lt($until);
    }

    public static function isWelcome(Restaurant $restaurant): bool
    {
        return self::enabled()
            && self::isNew($restaurant)
            && (int) $restaurant->rating_count < self::count();
    }

    /** What every sort and filter ranks the place by. */
    public static function shownValue(Restaurant $restaurant): float
    {
        return self::isWelcome($restaurant) ? self::WELCOME_VALUE : (float) $restaurant->rating_avg;
    }

    /**
     * `rating_avg` on a public place payload: the true average, except to an app before
     * 1.0.24 during the welcome — that build has no other field to show the 5.0 in.
     */
    public static function ratingAvgFor(Restaurant $restaurant, ?Request $request = null): float
    {
        if (self::isLegacyClient($request ?? request()) && self::isWelcome($restaurant)) {
            return self::WELCOME_VALUE;
        }

        return (float) $restaurant->rating_avg;
    }

    public static function isLegacyClient(?Request $request): bool
    {
        return ClientVersion::isBefore($request, ClientVersion::RATING_BADGE);
    }

    /**
     * The shown value as SQL, with its bindings: the same rule as {@see self::isWelcome()}
     * (`now < first_published_at + days` is `first_published_at > now − days`).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function shownSql(string $table = 'restaurants'): array
    {
        if (! self::enabled() || self::days() === 0 || self::count() === 0) {
            return ["{$table}.rating_avg", []];
        }

        return [
            "(case when {$table}.first_published_at is not null and {$table}.first_published_at > ? and {$table}.rating_count < ? then ".self::WELCOME_VALUE." else {$table}.rating_avg end)",
            [now()->subDays(self::days()), self::count()],
        ];
    }

    public static function orderByShown(Builder $query, string $direction = 'desc', string $table = 'restaurants'): Builder
    {
        [$sql, $bindings] = self::shownSql($table);

        return $query->orderByRaw($sql.' '.($direction === 'asc' ? 'asc' : 'desc'), $bindings);
    }

    public static function whereShownAtLeast(Builder $query, float $minimum, string $table = 'restaurants'): Builder
    {
        [$sql, $bindings] = self::shownSql($table);

        return $query->whereRaw($sql.' >= ?', [...$bindings, $minimum]);
    }
}
