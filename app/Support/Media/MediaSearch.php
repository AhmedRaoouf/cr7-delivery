<?php

namespace App\Support\Media;

use App\Enums\MediaUsage;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaAudience;
use App\Models\PlaceGroup;
use App\Models\Restaurant;
use App\Support\ArabicSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * The library's search box (doc 71 §8): one term, Arabic-folded, every word matched
 * against the name, the filename, the tags, the places the asset is assigned to, and the
 * words that name its usage type, source and visibility.
 *
 * Words are AND-ed and each word is OR-ed across the haystacks, the same shape as
 * {@see \App\Support\Lists\ListSearch} — and wrapped in one group so it never escapes the
 * caller's other filters.
 */
final class MediaSearch
{
    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public static function apply(Builder $query, string $term): Builder
    {
        $tokens = ArabicSearch::tokens($term);

        foreach ($tokens as $token) {
            $needle = '%'.ArabicSearch::escapeLike($token).'%';

            $query->where(function (Builder $group) use ($token, $needle): void {
                foreach (['media_assets.name_ar', 'media_assets.name_en', 'media_assets.filename'] as $column) {
                    $group->orWhereRaw(ArabicSearch::columnSql($column)." LIKE ? ESCAPE '!'", [$needle]);
                }

                $group->orWhereHas('tags', function (Builder $t) use ($needle): void {
                    $t->where(function (Builder $w) use ($needle): void {
                        $w->whereRaw(ArabicSearch::columnSql('media_tags.slug')." LIKE ? ESCAPE '!'", [$needle])
                            ->orWhereRaw(ArabicSearch::columnSql('media_tags.name_ar')." LIKE ? ESCAPE '!'", [$needle])
                            ->orWhereRaw(ArabicSearch::columnSql('media_tags.name_en')." LIKE ? ESCAPE '!'", [$needle]);
                    });
                });

                // «owner / place» — the places and groups the asset is assigned to.
                $group->orWhereHas('audiences', function (Builder $a) use ($needle): void {
                    $a->where(function (Builder $w) use ($needle): void {
                        $w->where(fn ($p) => $p->where('audience_type', MediaAudience::PLACE)
                            ->whereIn('audience_id', self::named(Restaurant::query(), $needle)))
                            ->orWhere(fn ($g) => $g->where('audience_type', MediaAudience::GROUP)
                                ->whereIn('audience_id', self::named(PlaceGroup::query(), $needle)));
                    });
                });

                // The words that name a usage, a source or a visibility are matched exactly.
                if (in_array($token, MediaUsage::values(), true)) {
                    $group->orWhereJsonContains('media_assets.usage_types', $token);
                }

                if (in_array($token, [MediaAsset::SOURCE_UPLOADED, MediaAsset::SOURCE_SHIPPED, MediaAsset::SOURCE_SYSTEM], true)) {
                    $group->orWhere('media_assets.source', $token);
                }

                if (in_array($token, array_map(fn ($v) => $v->value, MediaVisibility::cases()), true)) {
                    $group->orWhere('media_assets.visibility', $token);
                }
            });
        }

        return $query;
    }

    /** ids of the rows whose Arabic or English name matches the needle */
    private static function named(Builder $model, string $needle): Builder
    {
        return $model->select('id')->where(fn ($r) => $r
            ->whereRaw(ArabicSearch::columnSql('name_ar')." LIKE ? ESCAPE '!'", [$needle])
            ->orWhereRaw(ArabicSearch::columnSql('name_en')." LIKE ? ESCAPE '!'", [$needle]));
    }
}
