<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\MediaField;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Restaurant;
use App\Support\Media\MediaPicker;
use App\Support\FileUploader;
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

    private function withPublicGallery(
        array $data,
        Request $request,
        string $field,
        string $folder,
        ?Model $existing = null,
        ?MediaField $media = null,
    ): array {
        unset($data['remove_'.$field]);

        // Doc 71 — `<field>_media[]` refs from the picker, alongside any uploads.
        $picked = [];

        if ($media !== null) {
            foreach ((array) $request->input($field.'_media', []) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $picked[] = MediaPicker::resolve($request->user(), $media, $ref, $this->mediaPlace($request, $existing));
                }
            }
        }

        if ($request->hasFile($field) || $picked !== []) {
            $data[$field] = [
                ...($request->hasFile($field)
                    ? array_map(fn ($file) => FileUploader::storePublic($file, $folder), $request->file($field))
                    : []),
                ...$picked,
            ];

            return $data;
        }

        if ($this->wantsRemoval($request, $field)) {
            foreach ((array) ($existing?->getAttribute($field) ?? []) as $url) {
                FileUploader::deletePublic(is_string($url) ? $url : null);
            }
            $data[$field] = [];
        }

        return $data;
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
