<?php

namespace App\Http\Controllers\Concerns;

use App\Support\FileUploader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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

    private function withPrivateUpload(array $data, Request $request, string $field, string $folder): array
    {
        if ($request->hasFile($field)) {
            $data[$field] = FileUploader::storePrivate($request->file($field), $folder);
        }

        return $data;
    }
}
