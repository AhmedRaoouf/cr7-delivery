<?php

namespace App\Support\Media;

use App\Enums\MediaField;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\FileUploader;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Batch 72 — one product's gallery, written safely.
 *
 * `products.gallery` is a JSON list of URLs. Until this batch the only way to change it was
 * to replace the whole list, from a client that had read it some time earlier. This class is
 * the single writer for everything that touches the list:
 *
 *  - **one transaction, one row lock.** Every mutation re-reads the product `FOR UPDATE`, so
 *    two writers are serialised and the second one sees the first one's result;
 *  - **an optimistic token.** {@see self::version()} is the md5 of the stored list. A caller
 *    that sends `expected_version` and no longer matches gets `409 gallery_changed`, whose
 *    `meta` carries the current `gallery_items` and `gallery_version` — enough to re-render
 *    without another request. Omitting the token is allowed and means last-write-wins;
 *  - **files go after the commit.** A picture dropped from the list is deleted only once the
 *    row no longer names it, and `FileUploader::deletePublic()` still refuses a library file
 *    or one another field shows ({@see MediaFiles::isProtected()}); a file stored for a
 *    write that then failed is removed again.
 *
 * Identity of a picture is `own:<sha1(url)>` — the same handle the picker already hands out
 * ({@see OwnImages::ref()}) — so a client never invents one.
 */
final class ProductGallery
{
    /** @return list<string> the stored URLs, in stored order, duplicates and junk as stored */
    public static function urls(Product|array|null $source): array
    {
        $list = $source instanceof Product ? $source->gallery : $source;

        return array_values(array_filter((array) $list, fn ($url) => is_string($url) && $url !== ''));
    }

    public static function ref(string $url): string
    {
        return OwnImages::ref($url);
    }

    /** md5 of the JSON list — deterministic, and different for a reorder as well as a change. */
    public static function version(array $urls): string
    {
        return md5(json_encode(array_values($urls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array{ref: string, url: string, thumb_url: string, position: int}> */
    public static function items(Product $product): array
    {
        $items = [];

        foreach (self::dedupe(self::urls($product)) as $position => $url) {
            $items[] = [
                'ref' => self::ref($url),
                'url' => $url,
                'thumb_url' => ThumbUrl::for($url),
                'position' => $position,
            ];
        }

        return $items;
    }

    /** @param  list<string>  $urls */
    public static function dedupe(array $urls): array
    {
        return array_values(array_unique($urls));
    }

    /** `409 gallery_changed` when the caller's token is no longer the stored one. */
    public static function assertFresh(Product $product, ?string $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        if (! hash_equals(self::version(self::urls($product)), $expected)) {
            throw self::changed($product);
        }
    }

    // ── the replace path (PATCH /products/{id}) ─────────────────────────────────────

    /**
     * Run `$write` against the product row locked for update, inside one transaction.
     *
     * `$write` receives the locked product and does whatever it likes to it (the PATCH's
     * whole update goes here). The URLs the stored gallery held before and no longer holds
     * are deleted from disk after the commit; `$stored` is the list of files this write
     * uploaded, removed again if the write fails.
     *
     * @param  Closure(Product): mixed  $write
     * @param  list<string>  $stored  filled by the caller's closure by reference
     */
    public static function locked(Product $product, ?string $expected, bool $touchesGallery, array &$stored, Closure $write): Product
    {
        try {
            [$locked, $dropped] = DB::transaction(function () use ($product, $expected, $touchesGallery, $write) {
                $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

                if ($touchesGallery) {
                    self::assertFresh($locked, $expected);
                }

                $before = self::urls($locked);
                $write($locked);

                return [$locked, array_values(array_diff($before, self::urls($locked)))];
            });
        } catch (Throwable $e) {
            self::forget($stored);

            throw $e;
        }

        self::forget($dropped);

        return $locked;
    }

    // ── the per-picture API ─────────────────────────────────────────────────────────

    /**
     * Append pictures — refs first, then the uploaded files, each in the order sent.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $refs
     */
    public static function append(Product $product, User $actor, ?Restaurant $place, array $files, array $refs, ?string $expected): Product
    {
        // A refused ref is a 422 before anything is locked or written.
        $urls = [];

        foreach ($refs as $ref) {
            $urls[] = MediaPicker::resolve($actor, MediaField::ProductGallery, $ref, $place);
        }

        $urls = self::dedupe($urls);
        $stored = [];

        return self::mutate($product, $expected, function (array $current) use ($urls, $files, &$stored) {
            $fresh = array_values(array_diff($urls, $current));

            if (count($current) + count($fresh) + count($files) > Product::GALLERY_MAX) {
                throw self::full();
            }

            foreach ($files as $file) {
                $stored[] = $fresh[] = FileUploader::storePublic($file, 'products');
            }

            return [...$current, ...$fresh];
        }, $stored);
    }

    /** Remove the picture whose handle is `$ref` — it must be one of THIS product's entries. */
    public static function remove(Product $product, string $ref, ?string $expected): Product
    {
        $stored = [];

        return self::mutate($product, $expected, function (array $current) use ($ref) {
            $url = self::find($current, $ref);

            if ($url === null) {
                throw new ApiException('gallery_item_not_found', 'هذه الصورة ليست ضمن معرض المنتج', 404);
            }

            return array_values(array_filter($current, fn (string $u) => $u !== $url));
        }, $stored);
    }

    /**
     * Put the pictures in `$order` — which must be exactly the current entries, each once.
     * Reordering never adds and never deletes.
     *
     * @param  list<string>  $order
     */
    public static function reorder(Product $product, array $order, ?string $expected): Product
    {
        $stored = [];

        return self::mutate($product, $expected, function (array $current) use ($order) {
            $current = self::dedupe($current);
            $byRef = [];

            foreach ($current as $url) {
                $byRef[self::ref($url)] = $url;
            }

            $sent = array_values(array_filter($order, 'is_string'));
            $exact = count($sent) === count($order)
                && count($sent) === count($byRef)
                && count(array_unique($sent)) === count($sent)
                && array_diff($sent, array_keys($byRef)) === [];

            if (! $exact) {
                throw new ApiException(
                    'gallery_order_invalid',
                    'الترتيب المرسل يجب أن يضم كل صور المعرض مرة واحدة بالضبط',
                    422,
                    errors: ['order' => ['الترتيب المرسل يجب أن يضم كل صور المعرض مرة واحدة بالضبط']],
                );
            }

            return array_map(fn (string $ref) => $byRef[$ref], $sent);
        }, $stored);
    }

    // ── internals ───────────────────────────────────────────────────────────────────

    /**
     * @param  Closure(list<string>): list<string>  $change  current URLs → the new list
     * @param  list<string>  $stored  URLs the closure wrote to disk, removed again on failure
     */
    private static function mutate(Product $product, ?string $expected, Closure $change, array &$stored): Product
    {
        $locked = self::locked($product, $expected, true, $stored, function (Product $row) use ($change): void {
            $row->gallery = self::dedupe($change(self::urls($row)));
            $row->save();
        });

        return $locked;
    }

    /** @param  list<string>  $current */
    private static function find(array $current, string $ref): ?string
    {
        foreach ($current as $url) {
            if (hash_equals(self::ref($url), $ref)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * A file this write no longer needs. The row already says so, so «no other holder» is
     * zero here — not one, as it is for a caller that has not yet released the file.
     *
     * @param  list<string>  $urls
     */
    private static function forget(array $urls): void
    {
        foreach (array_unique($urls) as $url) {
            FileUploader::deletePublic($url, holders: 0);
        }
    }

    private static function changed(Product $product): ApiException
    {
        // Always called with the row read under the lock, so it *is* the current state.
        return new ApiException(
            'gallery_changed',
            'تم تعديل معرض صور المنتج من جهة أخرى — تم تحديث القائمة، راجعها ثم أعد المحاولة',
            409,
            meta: [
                'gallery_items' => self::items($product),
                'gallery_version' => self::version(self::urls($product)),
            ],
        );
    }

    private static function full(): ApiException
    {
        return new ApiException(
            'gallery_full',
            'بلغ معرض المنتج الحد الأقصى ‪'.Product::GALLERY_MAX.'‬ صورة — احذف صورة قبل إضافة أخرى',
            422,
            errors: ['gallery' => ['بلغ معرض المنتج الحد الأقصى']],
            meta: ['max' => Product::GALLERY_MAX],
        );
    }

    /** The 422 the replace path answers when the request names more pictures than the cap. */
    public static function tooMany(): ValidationException
    {
        return ValidationException::withMessages([
            'gallery' => ['لا يزيد عدد صور المنتج على ‪'.Product::GALLERY_MAX.'‬ صورة'],
        ]);
    }
}
