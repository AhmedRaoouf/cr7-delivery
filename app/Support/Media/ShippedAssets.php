<?php

namespace App\Support\Media;

use App\Enums\MediaVisibility;
use App\Exceptions\ApiException;
use App\Models\MediaAsset;
use App\Models\MediaTag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * «Available app images» (doc 71 §7): the pictures that ship inside the backend's
 * `public/` folder — its catalogue art, and the app's illustrations copied there by
 * `scripts/sync-shipped-assets.sh` — that are **not yet** in the library.
 *
 * Scanned live, never imported. A new file dropped into a shipped folder appears in the
 * Available list and stays out of every picker until an Admin adds it; that is the whole
 * point of keeping this list apart from `media_assets`.
 *
 * `catalog/menus` (printed menu sheets, shown whole in their own section — doc 06) and
 * `img/` (derived thumbnails) are not reusable pictures and are not scanned.
 */
final class ShippedAssets
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** @var array<string, array{source: string, usage: list<string>, tags: list<string>}> folder → defaults */
    private const FOLDERS = [
        'catalog/dishes' => ['source' => 'backend', 'usage' => ['product'], 'tags' => ['product', 'food']],
        'catalog/covers' => ['source' => 'backend', 'usage' => ['cover'], 'tags' => ['cover', 'restaurant']],
        'catalog/restaurants' => ['source' => 'backend', 'usage' => ['logo'], 'tags' => ['logo', 'restaurant']],
        'catalog/menu-icons' => ['source' => 'backend', 'usage' => ['category', 'icon'], 'tags' => ['category', 'icon', 'food']],
        'catalog/cuisines' => ['source' => 'backend', 'usage' => ['category'], 'tags' => ['category', 'food']],
        'catalog/store-categories' => ['source' => 'backend', 'usage' => ['category'], 'tags' => ['category']],
        'catalog/banners' => ['source' => 'backend', 'usage' => ['banner'], 'tags' => ['banner']],
        'catalog/home-popups' => ['source' => 'backend', 'usage' => ['banner'], 'tags' => ['banner']],
        'shipped/app/illustrations' => ['source' => 'app', 'usage' => ['banner'], 'tags' => ['illustration']],
        'shipped/app/brand' => ['source' => 'app', 'usage' => ['logo', 'icon'], 'tags' => ['brand']],
        'shipped/dashboard' => ['source' => 'dashboard', 'usage' => ['banner'], 'tags' => ['illustration']],
    ];

    /** store-categories file stem → the domain tag it belongs to */
    private const STORE_CATEGORY_TAGS = [
        'restaurants' => 'restaurant',
        'cafes' => 'cafe',
        'pharmacies' => 'pharmacy',
        'supermarkets' => 'grocery',
        'greengrocers' => 'grocery',
    ];

    /**
     * Every shipped picture, in-library or not.
     *
     * @return Collection<string, array{key: string, name: string, filename: string, source: string, folder: string, url: string, bytes: int, usage_types: list<string>, tags: list<string>}>
     */
    public static function all(): Collection
    {
        $items = [];

        foreach (self::FOLDERS as $folder => $defaults) {
            $dir = public_path($folder);

            if (! is_dir($dir)) {
                continue;
            }

            foreach (File::allFiles($dir) as $file) {
                $ext = strtolower($file->getExtension());

                if (! in_array($ext, self::EXTENSIONS, true)) {
                    continue;
                }

                $relative = $folder.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                $items[$relative] = self::describe($relative, $folder, $defaults, $file->getSize());
            }
        }

        ksort($items);

        return collect($items);
    }

    /**
     * @return Collection<string, array<string, mixed>> the shipped pictures not currently in the library
     */
    public static function available(): Collection
    {
        $inLibrary = MediaAsset::query()->whereNotNull('source_key')->pluck('source_key')->flip();

        return self::all()->reject(fn (array $item, string $key) => $inLibrary->has($key));
    }

    /**
     * Add the chosen shipped pictures to the library. A picture removed earlier is
     * restored rather than duplicated — its `source_key` is unique.
     *
     * @param  list<string>  $keys
     * @param  array{visibility?: string, usage_types?: list<string>, tags?: list<string>}  $overrides
     * @return list<MediaAsset>
     */
    public static function add(array $keys, ?User $actor, array $overrides = []): array
    {
        $all = self::all();
        $unknown = array_values(array_filter($keys, fn ($k) => ! $all->has($k)));

        if ($unknown !== []) {
            throw new ApiException(
                'shipped_asset_not_found',
                'بعض الصور غير موجودة بين صور التطبيق المتاحة',
                422,
                errors: ['keys' => $unknown],
            );
        }

        $added = [];

        foreach (array_unique($keys) as $key) {
            $item = $all[$key];
            $abs = public_path($key);
            [$w, $h, $mime] = self::probe($abs);

            $asset = MediaAsset::withTrashed()->where('source_key', $key)->first() ?? new MediaAsset;
            $wasNew = ! $asset->exists;

            $asset->fill([
                'name_ar' => $asset->name_ar ?: $item['name'],
                'name_en' => $asset->name_en ?: $item['name'],
                'filename' => $item['filename'],
                'disk' => 'web',
                'path' => $key,
                'mime' => $mime,
                'width' => $w,
                'height' => $h,
                'bytes' => $item['bytes'],
                'sha1' => sha1_file($abs) ?: null,
                'source' => MediaAsset::SOURCE_SHIPPED,
                'source_key' => $key,
                'usage_types' => $overrides['usage_types'] ?? $asset->usage_types ?? $item['usage_types'],
                'visibility' => $overrides['visibility'] ?? $asset->visibility?->value ?? MediaVisibility::Global->value,
                'created_by' => $asset->created_by ?? $actor?->id,
            ]);
            $asset->deleted_at = null;
            $asset->save();

            if ($wasNew || isset($overrides['tags'])) {
                $asset->tags()->sync(self::tagIds($overrides['tags'] ?? $item['tags']));
            }

            $added[] = $asset;
        }

        return $added;
    }

    /** @param  list<string>  $slugs */
    public static function tagIds(array $slugs): array
    {
        return collect($slugs)->map(function (string $slug) {
            $slug = mb_strtolower(trim($slug));

            return MediaTag::query()->firstOrCreate(
                ['slug' => $slug],
                ['name_ar' => $slug, 'name_en' => $slug],
            )->id;
        })->all();
    }

    /** @return array{0: ?int, 1: ?int, 2: ?string} */
    public static function probe(string $absolutePath): array
    {
        $info = @getimagesize($absolutePath);

        return $info === false
            ? [null, null, null]
            : [(int) $info[0], (int) $info[1], $info['mime'] ?? null];
    }

    /** @return array<string, mixed> */
    private static function describe(string $relative, string $folder, array $defaults, int|false $bytes): array
    {
        $filename = basename($relative);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $usage = $defaults['usage'];
        $tags = $defaults['tags'];

        if ($folder === 'catalog/store-categories') {
            if (str_starts_with($stem, 'icon-')) {
                $usage = ['icon'];
                $tags = ['icon'];
                $stem = substr($stem, 5);
            }

            if (isset(self::STORE_CATEGORY_TAGS[$stem])) {
                $tags[] = self::STORE_CATEGORY_TAGS[$stem];
            }
        }

        return [
            'key' => $relative,
            'name' => trim((string) preg_replace('/[\s_\-]+/', ' ', pathinfo($filename, PATHINFO_FILENAME))),
            'filename' => $filename,
            'source' => $defaults['source'],
            'folder' => $folder,
            'url' => asset($relative),
            'bytes' => (int) $bytes,
            'usage_types' => $usage,
            'tags' => array_values(array_unique($tags)),
        ];
    }
}
