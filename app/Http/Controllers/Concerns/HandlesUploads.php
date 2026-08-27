<?php

namespace App\Http\Controllers\Concerns;

use App\Support\FileUploader;
use Illuminate\Http\Request;

/** Swaps an uploaded file for its stored URL/path inside an already-validated data array. */
trait HandlesUploads
{
    private function withPublicUpload(array $data, Request $request, string $field, string $folder): array
    {
        if ($request->hasFile($field)) {
            $data[$field] = FileUploader::storePublic($request->file($field), $folder);
        }

        return $data;
    }

    private function withPublicGallery(array $data, Request $request, string $field, string $folder): array
    {
        if ($request->hasFile($field)) {
            $data[$field] = array_map(
                fn ($file) => FileUploader::storePublic($file, $folder),
                $request->file($field),
            );
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
