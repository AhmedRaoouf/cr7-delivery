<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\MediaField;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\FileUploader;
use App\Support\Media\MediaPicker;
use App\Support\Media\ProductGallery;
use App\Support\PdfPages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Swaps an uploaded file for its stored URL/path inside an already-validated data array.
 *
 * Every `withPublic*` helper also understands **removal**. A picture that can be set and
 * never unset is a bug an operator meets the first time they upload the wrong logo: the
 * dashboard offered no way back. The convention is a sibling boolean — `remove_logo`,
 * `remove_image`, `remove_gallery` — because a multipart body cannot carry a JSON null,
 * and an empty string field is indistinguishable from "not touched" in `FormData`.
 */
trait HandlesUploads
{
    private function wantsRemoval(Request $request, string $field): bool
    {
        return $request->boolean('remove_'.$field);
    }

    private function withPublicUpload(
        array $data,
        Request $request,
        string $field,
        string $folder,
        ?Model $existing = null,
        ?MediaField $media = null,
    ): array {
        // The flag is a transport detail; it is not a column on any model.
        unset($data['remove_'.$field]);

        if ($request->hasFile($field)) {
            $data[$field] = FileUploader::storePublic($request->file($field), $folder);
            FileUploader::deletePublic($existing?->getAttribute($field));

            return $data;
        }

        // Doc 71 — a picture picked from the media library instead of uploaded. The
        // server resolves the opaque `<field>_media` ref against the same rules the picker
        // list used, and stores the URL exactly as an upload would.
        if ($media !== null && ($url = $this->pickedMedia($request, $field, $media, $existing)) !== null) {
            if ($existing?->getAttribute($field) !== $url) {
                FileUploader::deletePublic($existing?->getAttribute($field));
            }

            $data[$field] = $url;

            return $data;
        }

        if ($this->wantsRemoval($request, $field)) {
            FileUploader::deletePublic($existing?->getAttribute($field));
            $data[$field] = null;
        }

        return $data;
    }

    /**
     * The `gallery` column of a product, from `gallery[]` uploads and `gallery_media[]` refs.
     *
     * **Replace semantics** (unchanged, and what every client older than batch 72 relies on):
     * naming any picture replaces the whole gallery, and naming none leaves it alone. What
     * batch 72 fixed around it:
     *
     *  - the stored order is the order sent — the picked refs in the order given, then the
     *    uploaded files — and a picture named twice is stored once;
     *  - more than {@see Product::GALLERY_MAX} pictures is a `422 validation_failed`;
     *  - a `gallery` key that arrived empty (`gallery=` in a multipart body reads as null) no
     *    longer wipes the stored list — `remove_gallery` is the only way to empty it;
     *  - nothing is deleted here. Dropped files are removed after the commit by
     *    {@see ProductGallery::locked()}, and `$stored` reports the files this call wrote so
     *    a failed write can take them back.
     *
     * @param  list<string>  $stored  filled with the URLs of the files uploaded by this call
     */
    private function withPublicGallery(
        array $data,
        Request $request,
        string $field,
        string $folder,
        ?Model $existing = null,
        ?MediaField $media = null,
        array &$stored = [],
    ): array {
        unset($data['remove_'.$field], $data[$field.'_media'], $data['expected_version']);

        // Doc 71 — `<field>_media[]` refs from the picker, alongside any uploads.
        $picked = [];

        if ($media !== null) {
            foreach ((array) $request->input($field.'_media', []) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $picked[] = MediaPicker::resolve($request->user(), $media, $ref, $this->mediaPlace($request, $existing));
                }
            }
        }

        $picked = array_values(array_unique($picked));
        $files = $request->hasFile($field) ? array_values((array) $request->file($field)) : [];

        if ($files !== [] || $picked !== []) {
            if (count($picked) + count($files) > Product::GALLERY_MAX) {
                throw ProductGallery::tooMany();
            }

            $urls = $picked;

            foreach ($files as $file) {
                $stored[] = $urls[] = FileUploader::storePublic($file, $folder);
            }

            $data[$field] = array_values(array_unique($urls));

            return $data;
        }

        if ($this->wantsRemoval($request, $field)) {
            $data[$field] = [];

            return $data;
        }

        // Nothing said about the gallery: leave the column alone.
        unset($data[$field]);

        return $data;
    }

    /** Whether this request names a gallery write at all (so `expected_version` applies). */
    private function touchesGallery(Request $request): bool
    {
        return $request->hasFile('gallery')
            || $this->wantsRemoval($request, 'gallery')
            || array_filter((array) $request->input('gallery_media', []), fn ($ref) => is_string($ref) && $ref !== '') !== [];
    }

    /**
     * A new product, its gallery written by {@see self::withPublicGallery()}.
     *
     * @param  array<string, mixed>  $data  the validated request
     */
    private function createProductWithGallery(Restaurant $restaurant, Request $request, array $data): Product
    {
        $stored = [];

        try {
            $data = $this->withPublicUpload($data, $request, 'image', 'products', media: MediaField::ProductImage);
            $data = $this->withPublicGallery($data, $request, 'gallery', 'products', media: MediaField::ProductGallery, stored: $stored);

            return $restaurant->products()->create($data);
        } catch (\Throwable $e) {
            foreach ($stored as $url) {
                FileUploader::deletePublic($url, holders: 0);
            }

            throw $e;
        }
    }

    /**
     * A product PATCH: the whole update in one transaction under a row lock, a stale
     * `expected_version` refused with `409 gallery_changed`, and the pictures the update
     * dropped deleted after the commit.
     *
     * @param  array<string, mixed>  $data  the validated request
     */
    private function updateProductWithGallery(Request $request, Product $product, array $data): Product
    {
        $stored = [];

        return ProductGallery::locked(
            $product,
            $request->input('expected_version'),
            $this->touchesGallery($request),
            $stored,
            function (Product $locked) use ($request, $data, &$stored): void {
                $data = $this->withPublicUpload($data, $request, 'image', 'products', $locked, MediaField::ProductImage);
                $data = $this->withPublicGallery($data, $request, 'gallery', 'products', $locked, MediaField::ProductGallery, $stored);

                $locked->update($data);
            },
        );
    }

    /**
     * A gallery whose members may be images **or** PDFs, each PDF expanded into
     * one image per page.
     *
     * This is `menu_sheets` (D-58 — «المنيو», pill 0 of the tab strip). The
     * column, the cast, the customer resource and the seeder all shipped in
     * 2026-08; the write path did not, so a printed menu could only ever arrive
     * through `database/data/catalog.json`. Nobody could upload one.
     *
     * PDFs become images here rather than in the app, so the pages swipe and
     * zoom through the same `ImageGallerySheet` every other picture uses and
     * neither client gains a PDF dependency. See {@see PdfPages}.
     *
     * Order is preserved across the mix: a PDF's pages land where that PDF sat
     * in the upload, which is the only ordering a person who dragged in
     * «menu-front.jpg, menu.pdf, menu-back.jpg» would expect.
     *
     * **An upload adds; it does not replace.** Unlike every other helper here,
     * this one is a gallery an operator builds over time — a menu is
     * photographed a sheet at a time, and a second visit that silently deleted
     * the first visit's pages would be the same defect `remove_logo` was added
     * to fix. What survives is named explicitly by `keep_<field>[]`, a list of
     * the already-stored URLs the form still shows; anything stored and not in
     * that list is deleted from disk. Omit the list entirely and everything is
     * kept, so a client that knows nothing about it can still append.
     *
     * `remove_<field>` still empties the gallery outright and wins over both.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withPublicSheets(
        array $data,
        Request $request,
        string $field,
        string $folder,
        ?Model $existing = null,
    ): array {
        unset($data['remove_'.$field], $data['keep_'.$field]);

        $stored = array_values(array_filter(
            (array) ($existing?->getAttribute($field) ?? []),
            is_string(...),
        ));

        $removing = $this->wantsRemoval($request, $field);
        $names = $request->has('keep_'.$field);

        // Nothing said about this field at all — leave the column alone. Writing
        // `$data[$field] = $stored` instead would be harmless today and a data
        // loss the day a caller passes no `$existing`.
        if (! $request->hasFile($field) && ! $names && ! $removing) {
            return $data;
        }

        $kept = match (true) {
            $removing => [],
            $names => array_values(array_intersect($stored, array_map(
                strval(...),
                array_filter((array) $request->input('keep_'.$field), is_scalar(...)),
            ))),
            default => $stored,
        };

        foreach (array_diff($stored, $kept) as $dropped) {
            FileUploader::deletePublic($dropped);
        }

        if ($request->hasFile($field)) {
            $urls = $kept;

            foreach ($request->file($field) as $file) {
                $isPdf = strtolower((string) $file->getClientOriginalExtension()) === 'pdf'
                    || $file->getMimeType() === 'application/pdf';

                try {
                    $pages = $isPdf ? PdfPages::rasterise($file) : [$file];
                } catch (RuntimeException $e) {
                    // A 500 here reads to the operator as «the dashboard is
                    // broken». Each of these is something they can act on.
                    throw new ApiException($e->getMessage(), match ($e->getMessage()) {
                        'pdf_unsupported' => 'لا يمكن قراءة ملفات PDF على هذا الخادم — ارفع صور الصفحات بدلًا من ذلك.',
                        'pdf_too_many_pages' => 'الملف يحتوي على صفحات أكثر من المسموح به — الحد الأقصى ‪'.PdfPages::MAX_PAGES.'‬ صفحة.',
                        default => 'تعذّرت قراءة ملف PDF — تأكد من أن الملف سليم أو ارفع صور الصفحات.',
                    }, 422);
                }

                foreach ($pages as $page) {
                    $urls[] = FileUploader::storePublic($page, $folder);
                }
            }

            $data[$field] = $urls;

            return $data;
        }

        $data[$field] = $kept;

        return $data;
    }

    /** The URL behind a `<field>_media` ref, or null when the request carries none. */
    private function pickedMedia(Request $request, string $field, MediaField $media, ?Model $existing): ?string
    {
        $ref = $request->input($field.'_media');

        if (! is_string($ref) || $ref === '') {
            return null;
        }

        return MediaPicker::resolve($request->user(), $media, $ref, $this->mediaPlace($request, $existing));
    }

    /**
     * The place a picker call is about: a place's own restaurant, or — for an admin — the
     * restaurant in the route or the one being edited. Null when there is none yet (a
     * restaurant being created), in which case only library pictures can be chosen.
     */
    private function mediaPlace(Request $request, ?Model $existing): ?Restaurant
    {
        $user = $request->user();

        if ($user->type === UserType::Restaurant) {
            return $user->restaurant()->first();
        }

        $routed = $request->route('restaurant');

        if ($routed instanceof Restaurant) {
            return $routed;
        }

        if ($existing instanceof Restaurant) {
            return $existing;
        }

        $restaurantId = $existing?->getAttribute('restaurant_id');

        return $restaurantId ? Restaurant::query()->find($restaurantId) : null;
    }

    private function withPrivateUpload(array $data, Request $request, string $field, string $folder): array
    {
        if ($request->hasFile($field)) {
            $data[$field] = FileUploader::storePrivate($request->file($field), $folder);
        }

        return $data;
    }
}
