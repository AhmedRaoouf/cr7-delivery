<?php

namespace App\Support\Media;

use App\Enums\MediaField;
use App\Enums\MediaUsage;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\MediaAsset;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Lists\ListSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The picker (doc 71 §5): one server-driven answer to «which pictures may this actor put
 * in this field», and the matching resolver that turns a chosen `ref` back into a URL.
 *
 * Both sides call the same rules, so a client cannot pick what the list would not have
 * shown it, and no client re-implements a visibility rule.
 *
 * A restaurant actor sees: its own current pictures + the library assets visible to it
 * ({@see MediaLibrary::visibleTo()}), for the field's usage only. An admin sees every
 * non-removed library asset (hidden ones included — they are the Admin's to place), plus
 * the own pictures of the place being edited when there is one.
 */
final class MediaPicker
{
    /**
     * @param  array{q?: string, tag?: string, per_page?: int, page?: int}  $filters
     * @return array{items: list<array<string, mixed>>, page: LengthAwarePaginator}
     */
    public static function list(User $actor, MediaField $field, ?Restaurant $place, array $filters = []): array
    {
        $usage = $field->usage()->value;
        $term = trim((string) ($filters['q'] ?? ''));
        $tag = trim((string) ($filters['tag'] ?? ''));

        $query = self::libraryQuery($actor, $place)->forUsage($usage)->with('tags:id,slug,name_ar,name_en');

        if ($tag !== '') {
            $query->whereHas('tags', fn (Builder $t) => $t->where('slug', $tag));
        }

        MediaSearch::apply($query, $term);

        $page = $query->orderBy('name_ar')->orderBy('id')
            ->paginate(max(1, min(60, (int) ($filters['per_page'] ?? 24))), page: (int) ($filters['page'] ?? 1));

        $items = [];

        // The place's own pictures lead page one, then the library.
        if ($page->currentPage() === 1 && $place !== null && $tag === '') {
            $libraryUrls = [];
            $own = OwnImages::of($place)->where('usage', $usage);

            if ($term !== '') {
                $needle = \App\Support\ArabicSearch::normalize($term);
                $own = $own->filter(fn (array $o) => str_contains(\App\Support\ArabicSearch::normalize($o['name']), $needle));
            }

            foreach ($own as $o) {
                $items[] = [
                    'ref' => $o['ref'],
                    'id' => null,
                    'source' => 'own',
                    'name' => $o['name'],
                    'url' => $o['url'],
                    'usage_types' => [$o['usage']],
                    'tags' => [],
                    'width' => null,
                    'height' => null,
                    'used_by' => $o['used_by'],
                ];
                $libraryUrls[$o['url']] = true;
            }

            foreach ($page->items() as $asset) {
                if (! isset($libraryUrls[$asset->url])) {
                    $items[] = self::libraryItem($asset);
                }
            }

            return ['items' => $items, 'page' => $page];
        }

        foreach ($page->items() as $asset) {
            $items[] = self::libraryItem($asset);
        }

        return ['items' => $items, 'page' => $page];
    }

    /**
     * A chosen `ref` → the URL to store, or a 422 saying why the actor may not use it.
     */
    public static function resolve(User $actor, MediaField $field, string $ref, ?Restaurant $place): string
    {
        $usage = $field->usage()->value;

        if (preg_match('/^asset:(\d+)$/', $ref, $m) === 1) {
            $asset = self::libraryQuery($actor, $place)->forUsage($usage)->whereKey((int) $m[1])->first();

            if ($asset === null) {
                throw self::refused();
            }

            return $asset->url;
        }

        if (preg_match('/^own:([0-9a-f]{40})$/', $ref, $m) === 1 && $place !== null) {
            $own = OwnImages::of($place)->where('usage', $usage)
                ->first(fn (array $o) => $o['ref'] === 'own:'.$m[1]);

            if ($own !== null) {
                return $own['url'];
            }
        }

        throw self::refused();
    }

    /** @return Builder<MediaAsset> */
    private static function libraryQuery(User $actor, ?Restaurant $place): Builder
    {
        if ($actor->type === UserType::Admin) {
            return MediaAsset::query();
        }

        // A place actor is always scoped to its own restaurant, whatever the caller passed.
        $own = $actor->restaurant()->first();

        return $own === null ? MediaAsset::query()->whereRaw('1 = 0') : MediaLibrary::visibleTo($own);
    }

    private static function refused(): ApiException
    {
        return new ApiException('media_not_allowed', 'هذه الصورة غير متاحة لهذا الحقل', 422, errors: ['media' => ['هذه الصورة غير متاحة لهذا الحقل']]);
    }

    /** @return array<string, mixed> */
    public static function libraryItem(MediaAsset $asset): array
    {
        return [
            'ref' => 'asset:'.$asset->id,
            'id' => $asset->id,
            'source' => 'library',
            'name' => $asset->name_ar,
            'url' => $asset->url,
            'usage_types' => $asset->usage_types,
            'tags' => $asset->tags->pluck('slug')->all(),
            'width' => $asset->width,
            'height' => $asset->height,
            'used_by' => null,
        ];
    }
}
