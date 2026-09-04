<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * doc 12 §5 field-level restrictions — public-facing images (products, restaurants,
 * banners, avatars) go on the configured public disk and return a browsable URL.
 * Sensitive files (payment proofs, driver documents — "Admin only") go on the `local`
 * disk, which has no public URL at all; they are served through an admin-gated endpoint.
 *
 * Which disk is "public" is `filesystems.public_uploads` — `public` locally,
 * `r2` on the deployment. That indirection is the fix for a bug that shipped: the
 * `public` disk serves through a `public/storage` symlink, Hostinger's shared hosting
 * did not have one, and so every upload answered 200 with a URL that answered 404.
 */
class FileUploader
{
    /**
     * Extensions we are willing to write into a web-served directory.
     *
     * The extension is derived from the file's own contents, never from the name the
     * client sent. `getClientOriginalExtension()` returns whatever was typed after the
     * last dot, so a valid PNG uploaded as `x.php` passed `image` validation and landed
     * as an executable path on the public disk.
     *
     * SVG is deliberately absent even though Laravel's `image` rule allows it: an SVG
     * is a document that can carry script, and served from the API's own origin that is
     * stored XSS.
     */
    private const SAFE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    private const SAFE_DOCUMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public static function disk(): string
    {
        return (string) config('filesystems.public_uploads', 'public');
    }

    public static function storePublic(UploadedFile $file, string $folder): string
    {
        $disk = self::disk();
        $name = self::filename($file, self::SAFE_IMAGE_EXTENSIONS);

        // `putFileAs` returns false on a failed write, and the `public` disk is
        // configured with `throw => false`. Without this check a storage failure is
        // invisible and the caller saves a URL for a file that was never written.
        $stored = Storage::disk($disk)->putFileAs($folder, $file, $name, 'public');

        if ($stored === false) {
            Log::error('upload failed', ['disk' => $disk, 'folder' => $folder, 'name' => $name]);

            throw new RuntimeException("Could not write {$folder}/{$name} to the {$disk} disk.");
        }

        return Storage::disk($disk)->url($stored);
    }

    /** @return string the stored relative path (not a URL — the disk is not web-accessible). */
    public static function storePrivate(UploadedFile $file, string $folder): string
    {
        return $file->storeAs($folder, self::filename($file, self::SAFE_DOCUMENT_EXTENSIONS), 'local');
    }

    /**
     * Remove a previously stored public file, given the URL that was saved for it.
     *
     * Best effort by design: rows seeded with `/catalog/...` URLs point at files this
     * application never uploaded, and a delete must not fail because the old image was
     * not ours to remove.
     */
    public static function deletePublic(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $disk = self::disk();
        $base = rtrim((string) Storage::disk($disk)->url(''), '/');

        if ($base === '' || ! str_starts_with($url, $base)) {
            return;
        }

        $path = ltrim(substr($url, strlen($base)), '/');

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('upload delete failed', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /** @param  list<string>  $allowed */
    private static function filename(UploadedFile $file, array $allowed): string
    {
        // `extension()` guesses from the MIME type of the bytes on disk; `guessExtension`
        // is its Symfony name. Either can return null for a type PHP does not know.
        $extension = strtolower((string) $file->extension());

        if (! in_array($extension, $allowed, true)) {
            $extension = 'bin';
        }

        return Str::uuid()->toString().'.'.$extension;
    }
}
