<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The photos a complaint carries, turned into URLs this server owns.
 *
 * `CUS-40` attaches up to three images. They used to travel as the **device's own file
 * paths** and were written into `complaints.attachments` verbatim, so the operator on
 * `ADM-41` and the restaurant on `RES-31` were handed `/data/user/0/com.cr7…/x.jpg` and
 * rendered a broken image. Files now come up multipart and land on the public disk, next
 * to every other image the platform serves.
 *
 * Public rather than private on purpose: doc 24 §4.3 mirrors a food complaint to the
 * restaurant it is about, and a picture of the wrong order is the whole evidence. The
 * name is a UUID, so the URL is not enumerable.
 *
 * @see FileUploader::storePublic()
 */
abstract class ComplaintAttachments
{
    private const FOLDER = 'complaints';

    /** @return list<string> the stored URLs, in the order they were sent. */
    public static function store(Request $request, string $field = 'attachments'): array
    {
        $files = $request->file($field);

        if ($files === null) {
            return [];
        }

        $urls = [];

        foreach (is_array($files) ? $files : [$files] as $file) {
            if ($file instanceof UploadedFile) {
                $urls[] = FileUploader::storePublic($file, self::FOLDER);
            }
        }

        return $urls;
    }
}
