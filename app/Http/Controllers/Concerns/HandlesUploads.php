<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ApiException;
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
    ): array {
        // The flag is a transport detail; it is not a column on any model.
        unset($data['remove_'.$field]);

        if ($request->hasFile($field)) {
            $data[$field] = FileUploader::storePublic($request->file($field), $folder);
            FileUploader::deletePublic($existing?->getAttribute($field));

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
    ): array {
        unset($data['remove_'.$field]);

        if ($request->hasFile($field)) {
            $data[$field] = array_map(
                fn ($file) => FileUploader::storePublic($file, $folder),
                $request->file($field),
            );

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
        unset($data['remove_'.$field]);

        if ($request->hasFile($field)) {
            $urls = [];

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

        if ($this->wantsRemoval($request, $field)) {
            foreach ((array) ($existing?->getAttribute($field) ?? []) as $url) {
                FileUploader::deletePublic(is_string($url) ? $url : null);
            }
            $data[$field] = [];
        }

        return $data;
    }

    private function withPrivateUpload(array $data, Request $request, string $field, string $folder): array
    {
        if ($request->hasFile($field)) {
            $data[$field] = FileUploader::storePrivate($request->file($field), $folder);
        }

        return $data;
    }
}
