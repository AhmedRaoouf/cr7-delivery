<?php

namespace App\Support\Media;

use App\Models\MediaAsset;
use App\Support\FileUploader;

/**
 * The «may this stored file be deleted» rule (doc 71 §6), asked by
 * {@see FileUploader::deletePublic()} before it touches a disk.
 */
final class MediaFiles
{
    public static function isProtected(string $disk, string $path, string $url): bool
    {
        // Anything under the library's own folder is the library's, asset row or not.
        if (str_starts_with($path, MediaAsset::FOLDER.'/')) {
            return true;
        }

        if (MediaAsset::withTrashed()->where('disk', $disk)->where('path', $path)->exists()) {
            return true;
        }

        // The caller is normally one of the holders, so «more than one» means another
        // field still shows it.
        return MediaReferences::count($url) > 1;
    }
}
