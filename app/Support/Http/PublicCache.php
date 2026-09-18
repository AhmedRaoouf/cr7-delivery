<?php

namespace App\Support\Http;

use App\Console\Commands\PruneExpiredCacheCommand;
use App\Models\ServiceZone;
use App\Models\User;
use App\Support\FirstOrderPerk;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Batch 68 — the server-side half of the public read cache (ADMIN-LISTS.md §5).
 *
 * The customer home opens on six public reads — config, cuisines, store categories,
 * banners, the first page of places and the popups — and every one was built from the
 * database on every open, by every phone, identically. Each is now built once per
 * version and served from the cache store until something it depends on changes.
 *
 * **Invalidation is a version, not a delete.** The cache store here is the database, which
 * has no tags and no pattern delete, so a key cannot be found in order to be forgotten.
 * Instead every key carries its group's version, and a write to any model the group reads
 * replaces the version ({@see bump()}); the old entries are never read again and expire
 * on their TTL ({@see PruneExpiredCacheCommand} removes the rows).
 *
 * **Only bounded keys are stored.** A database store keeps every key it is handed until it
 * is pruned, so a key built from anything a caller can vary freely — a search term, a
 * page far down the list, a coordinate that is not one of ours, a parameter no client
 * sends — would let any caller fill the table. Those requests are answered normally (with
 * an ETag, so a repeat is still a 304) and are simply not stored.
 */
final class PublicCache
{
    public const GROUPS = ['catalog', 'config'];

    /** The query keys a stored response may be keyed by. Anything else is not stored. */
    private const KEYS = [
        'page', 'per_page', 'lat', 'lng', 'city_id', 'zone_id', 'sort', 'category_id', 'selectable', 'active_only',
        'filter.store_category', 'filter.store_category_id', 'filter.cuisine_id', 'filter.free_delivery',
        'filter.open_now', 'filter.price_band',
    ];

    /** Past this page nobody polls, and each page is a key. */
    private const MAX_PAGE = 3;

    /** @var array<string, string> the request's own memo of each group's version */
    private static array $versions = [];

    public static function version(string $group): string
    {
        return self::$versions[$group] ??= (string) Cache::rememberForever(
            self::versionKey($group),
            static fn (): string => Str::random(12),
        );
    }

    /** A write to something the group reads: every stored response for it is stale. */
    public static function bump(string ...$groups): void
    {
        foreach ($groups as $group) {
            $version = Str::random(12);
            Cache::forever(self::versionKey($group), $version);
            self::$versions[$group] = $version;
        }
    }

    /** Forget the per-request memo — a long-lived worker must not keep an old version. */
    public static function flushMemo(): void
    {
        self::$versions = [];
    }

    /**
     * The cache key for this request, or null when it must not be stored.
     *
     * @param  string|null  $viewer  the audience class when the response depends on it
     */
    public static function keyFor(Request $request, string $group, ?string $viewer): ?string
    {
        $query = Arr::dot($request->query());

        foreach ($query as $key => $value) {
            // `filter.store_category.0` → `filter.store_category`
            $name = preg_replace('/\.\d+$/', '', (string) $key);

            if (! in_array($name, self::KEYS, true) || (is_string($value) && strlen($value) > 64)) {
                return null;
            }
        }

        if ((int) ($query['page'] ?? 1) > self::MAX_PAGE) {
            return null;
        }

        if ((isset($query['lat']) || isset($query['lng'])) && ! self::isKnownPoint($query['lat'] ?? null, $query['lng'] ?? null)) {
            return null;
        }

        ksort($query);

        $fingerprint = implode('|', [
            $request->getSchemeAndHttpHost(),
            $request->path(),
            app()->getLocale(),
            $viewer ?? '-',
            http_build_query($query),
        ]);

        return 'http:'.$group.':'.self::version($group).':'.hash('xxh128', $fingerprint);
    }

    /**
     * Who the response is for, as far as a public read can tell: `guest`, or a signed-in
     * account that is `first` (its first order is still ahead, D-86) or `returning`.
     * Banners, popups and `/config`'s `first_order` block are exactly that coarse.
     */
    public static function viewer(Request $request): string
    {
        // The same resolution the controllers behind it use, so the key and the body can
        // never disagree about who asked. Without a token it answers without a query.
        $user = auth('sanctum')->user();

        if (! $user instanceof User) {
            return 'guest';
        }

        return FirstOrderPerk::appliesTo($user) ? 'first' : 'returning';
    }

    /** A weak validator over the body: equal bodies, equal tags, whatever built them. */
    public static function etag(string $body): string
    {
        return hash('xxh128', $body);
    }

    /**
     * The app sends the chosen area's own centre as `lat`/`lng` — a point from our table,
     * not the phone's GPS. Only those are stored; any other point is answered, not kept.
     */
    private static function isKnownPoint(mixed $lat, mixed $lng): bool
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return false;
        }

        $points = Cache::remember(
            'http:catalog:'.self::version('catalog').':points',
            now()->addMinutes(10),
            static fn (): array => ServiceZone::query()
                ->whereNotNull('center_lat')
                ->get(['center_lat', 'center_lng'])
                ->map(fn (ServiceZone $zone) => sprintf('%.4f,%.4f', $zone->center_lat, $zone->center_lng))
                ->unique()
                ->values()
                ->all(),
        );

        return in_array(sprintf('%.4f,%.4f', (float) $lat, (float) $lng), $points, true);
    }

    private static function versionKey(string $group): string
    {
        return 'http:version:'.$group;
    }
}
