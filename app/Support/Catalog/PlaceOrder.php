<?php

namespace App\Support\Catalog;

use App\Models\ServiceZone;
use App\Support\SettingsCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Doc 87 — the order the customer meets the places in when they have not chosen one.
 *
 * Two layers, both the operator's:
 *
 * 1. **Pinned places** (`restaurants.pin_position`), first, in the operator's own order.
 * 2. **Everything else**, by the preset in `catalog.places_order`.
 *
 * A customer who picks a sort in the filter sheet gets exactly that sort: the operator's
 * order is the default, never an override of the customer's own choice.
 */
final class PlaceOrder
{
    public const SETTING = 'catalog.places_order';

    public const DEFAULT_MODE = 'featured';

    /** What a client sends to ask for the operator's order explicitly. */
    public const RECOMMENDED = 'recommended';

    /**
     * The presets. `value` is stored; the labels are what both admin panels show.
     *
     * @return list<array{value: string, label_ar: string, label_en: string}>
     */
    public static function modes(): array
    {
        return [
            ['value' => 'featured', 'label_ar' => 'المميّزة أولًا ثم ترتيب العرض', 'label_en' => 'Featured first, then display order'],
            ['value' => 'top_rated', 'label_ar' => 'الأعلى تقييمًا', 'label_en' => 'Best rated'],
            ['value' => 'most_ordered', 'label_ar' => 'الأكثر طلبًا', 'label_en' => 'Most ordered'],
            ['value' => 'newest', 'label_ar' => 'الأحدث إضافة', 'label_en' => 'Newest'],
            ['value' => 'fastest', 'label_ar' => 'الأسرع توصيلًا', 'label_en' => 'Fastest delivery'],
            ['value' => 'alphabetical', 'label_ar' => 'أبجديًا', 'label_en' => 'Alphabetical'],
            ['value' => 'daily_shuffle', 'label_ar' => 'تبديل يومي عادل', 'label_en' => 'Fair daily shuffle'],
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::modes(), 'value');
    }

    public static function mode(): string
    {
        $mode = (string) SettingsCatalog::get(self::SETTING);

        return in_array($mode, self::values(), true) ? $mode : self::DEFAULT_MODE;
    }

    /** Pinned places first, in their order, then the preset, then the id — a total order. */
    public static function apply(Builder $query, ?string $mode = null): Builder
    {
        $query
            ->orderByRaw('restaurants.pin_position is null')
            ->orderBy('restaurants.pin_position');

        self::applyPreset($query, $mode ?? self::mode());

        return $query->orderBy('restaurants.id');
    }

    private static function applyPreset(Builder $query, string $mode): void
    {
        match ($mode) {
            'top_rated' => $query->orderByDesc('restaurants.rating_avg')->orderByDesc('restaurants.orders_count'),
            'most_ordered' => $query->orderByDesc('restaurants.orders_count')->orderByDesc('restaurants.rating_avg'),
            'newest' => $query->orderByDesc('restaurants.created_at'),
            // The zone's own average, as the customer's «الأسرع» sort reads it. A place
            // with no zone figure goes last rather than first.
            'fastest' => $query
                ->orderByRaw('(select avg_delivery_minutes from service_zones where service_zones.id = restaurants.service_zone_id) is null')
                ->orderBy(ServiceZone::query()
                    ->select('avg_delivery_minutes')
                    ->whereColumn('service_zones.id', 'restaurants.service_zone_id')),
            'alphabetical' => $query->orderBy('restaurants.name_ar'),
            // Every place gets its turn near the top: the order is a hash of the id and
            // today's date, so it holds all day (and on every page) and moves at midnight.
            'daily_shuffle' => $query->orderByRaw('crc32(concat(restaurants.id, ?))', [now()->toDateString()]),
            default => $query->orderByDesc('restaurants.is_featured')->orderBy('restaurants.sort_order'),
        };
    }

    /**
     * Whether a `GET /restaurants` is asking for the default order.
     *
     * No `sort`, or `sort=recommended`. And, for an app before 1.0.22 — which sent
     * `sort=nearest` on every request because «الأقرب» was its default — a `nearest`
     * with none of the filter sheet's own filters, which is that app's untouched list.
     */
    public static function isRequested(Request $request): bool
    {
        $sort = (string) $request->query('sort', '');

        if ($sort === '' || $sort === self::RECOMMENDED) {
            return true;
        }

        if ($sort !== 'nearest' || ! self::isLegacyClient($request)) {
            return false;
        }

        $filter = $request->array('filter');

        foreach (['min_rating', 'free_delivery', 'open_now', 'price_band', 'max_delivery_fee'] as $key) {
            if (($filter[$key] ?? null) !== null && ($filter[$key] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    /** An app that always sent `sort=nearest` (every build before 1.0.22). */
    public static function isLegacyClient(?Request $request): bool
    {
        if ($request === null || $request->header('X-App') !== 'app' || ! $request->hasHeader('X-App-Version')) {
            return false;
        }

        return version_compare((string) $request->header('X-App-Version'), '1.0.22', '<');
    }
}
