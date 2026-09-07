<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * A multipart field of images, turned into URLs this server owns.
 *
 * Was `ComplaintAttachments`, with the folder baked in. It stopped being about complaints
 * the moment errand receipts needed the same treatment, and a receipt filed under
 * `complaints/` is a thing nobody will ever find again.
 *
 * Public rather than private on purpose, for both callers. doc 24 §4.3 mirrors a food
 * complaint to the restaurant it is about, and a picture of the wrong order is the whole
 * evidence; a captain's till receipt exists precisely so the customer can read the
 * amount they are being asked for. Names are UUIDs, so no URL is enumerable.
 *
 * @see FileUploader::storePublic()
 */
abstract class UploadedImages
{
    /** @return list<string> the stored URLs, in the order they were sent. */
    public static function store(Request $request, string $field, string $folder): array
    {
        $files = $request->file($field);

        if ($files === null) {
            return [];
        }

        $urls = [];

        foreach (is_array($files) ? $files : [$files] as $file) {
            if ($file instanceof UploadedFile) {
                $urls[] = FileUploader::storePublic($file, $folder);
            }
        }

        return $urls;
    }
}
