<?php

namespace App\Support\Media;

use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaAudience;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Read side of the media library (doc 71): what a place may see, and the platform's
 * default picture. Every visibility decision lives here — no client and no controller
 * repeats it.
 */
final class MediaLibrary
{
    public const PLACEHOLDER_KEY = 'default_placeholder';

    private const PLACEHOLDER_CACHE = 'media.placeholder_url';

    /**
     * The library assets a place is allowed to pick (doc 71 §4): global ones, plus the
     * restricted ones that name this place, a group it belongs to, or its store category.
     * `hidden` assets and removed ones are never offered.
     *
     * @return Builder<MediaAsset>
     */
    public static function visibleTo(Restaurant $restaurant): Builder
    {
        $groupIds = $restaurant->placeGroups()->pluck('place_groups.id')->all();

        return MediaAsset::query()->where(function (Builder $q) use ($restaurant, $groupIds): void {
            $q->where('visibility', MediaVisibility::Global->value)
                ->orWhere(function (Builder $r) use ($restaurant, $groupIds): void {
                    $r->where('visibility', MediaVisibility::Restricted->value)
                        ->whereHas('audiences', function (Builder $a) use ($restaurant, $groupIds): void {
                            $a->where(function (Builder $w) use ($restaurant, $groupIds): void {
                                $w->where(fn ($x) => $x->where('audience_type', MediaAudience::PLACE)
                                    ->where('audience_id', $restaurant->id));

                                if ($groupIds !== []) {
                                    $w->orWhere(fn ($x) => $x->where('audience_type', MediaAudience::GROUP)
                                        ->whereIn('audience_id', $groupIds));
                                }

                                if ($restaurant->store_category_id !== null) {
                                    $w->orWhere(fn ($x) => $x->where('audience_type', MediaAudience::STORE_CATEGORY)
                                        ->where('audience_id', $restaurant->store_category_id));
                                }
                            });
                        });
                });
        });
    }

    /** The protected system asset shown where a product, category or cover has no picture. */
    public static function placeholderUrl(): ?string
    {
        return Cache::remember(self::PLACEHOLDER_CACHE, 300, function (): ?string {
            $asset = MediaAsset::withTrashed()->where('system_key', self::PLACEHOLDER_KEY)->first();

            return $asset?->url;
        });
    }

    public static function forgetPlaceholder(): void
    {
        Cache::forget(self::PLACEHOLDER_CACHE);
    }

    /** A stored URL, or the placeholder when there is none. Never replaces a real one. */
    public static function orPlaceholder(?string $url): ?string
    {
        return is_string($url) && $url !== '' ? $url : self::placeholderUrl();
    }
}
