<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * doc 12 §5 field-level restrictions — public-facing images (products, restaurants,
 * banners, avatars) go on the `public` disk and return a browsable URL. Sensitive
 * files (payment proofs, driver documents — "Admin only") go on the `local` disk,
 * which has no public URL at all; they are served through an admin-gated endpoint.
 */
class FileUploader
{
    public static function storePublic(UploadedFile $file, string $folder): string
    {
        $path = $file->storeAs($folder, self::filename($file), 'public');

        return Storage::disk('public')->url($path);
    }

    /** @return string the stored relative path (not a URL — the disk is not web-accessible). */
    public static function storePrivate(UploadedFile $file, string $folder): string
    {
        return $file->storeAs($folder, self::filename($file), 'local');
    }

    private static function filename(UploadedFile $file): string
    {
        return Str::uuid().'.'.$file->getClientOriginalExtension();
    }
}
