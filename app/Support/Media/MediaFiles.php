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
    /**
     * @param  int  $holders  how many stored fields the caller itself still accounts for: `1` when
     *                        it is about to release the file (the default — the row still names
     *                        it), `0` when the row was already updated and the file is being
     *                        cleaned up afterwards, so any remaining holder is «another field».
     */
    public static function isProtected(string $disk, string $path, string $url, int $holders = 1): bool
    {
        // Anything under the library's own folder is the library's, asset row or not.
        if (str_starts_with($path, MediaAsset::FOLDER.'/')) {
            return true;
        }

        if (MediaAsset::withTrashed()->where('disk', $disk)->where('path', $path)->exists()) {
            return true;
        }

        // Normally the caller is one of the holders, so «more than one» means another
        // field still shows it.
        return MediaReferences::count($url) > $holders;
    }
}
