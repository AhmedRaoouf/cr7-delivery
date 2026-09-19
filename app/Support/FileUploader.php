<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Support\Media\MediaFiles;
use App\Support\Security\ImageMetadata;
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
 * Which disk is "public" is `filesystems.public_uploads`, and it is `public` everywhere:
 * storage/app/public, served through the `public/storage` symlink. The indirection stays
 * because it is what makes the disk a deployment decision rather than a code change, and
 * because of the bug that created it — the symlink was missing on the server, so every
 * upload answered 200 with a URL that answered 404. `storage:link` has been run since,
 * and `/storage/` on the live host answers 403 (a directory that exists and refuses to
 * list itself) rather than the 301 a missing path gets.
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
        $disk = (string) config('filesystems.public_uploads', 'public');

        // A deployment whose `.env` still names a disk this build no longer defines would
        // otherwise throw «Disk [r2] does not have a configured driver» on the first
        // upload — a leftover env line taking down a working feature, on the one code
        // path where the failure is a 500 rather than a missing image. Fall back to the
        // disk that is always defined, and say so rather than failing silently.
        //
        // Resolved through the manager, not by reading `filesystems.disks.*`: a disk
        // injected by `Storage::fake()` is registered on the manager and never appears in
        // the config, so a config lookup would send every test that fakes a disk to
        // `public` instead.
        try {
            Storage::disk($disk);
        } catch (\InvalidArgumentException $e) {
            Log::warning('upload disk is not configured; falling back', ['configured' => $disk]);

            return 'public';
        }

        return $disk;
    }

    public static function storePublic(UploadedFile $file, string $folder): string
    {
        return Storage::disk(self::disk())->url(self::storePublicPath($file, $folder));
    }

    /**
     * {@see self::storePublic()}, answering with the stored path on the uploads disk —
     * what the media library keeps beside the URL, so it can compute either later.
     */
    public static function storePublicPath(UploadedFile $file, string $folder): string
    {
        // Resolution first, bytes second — see `ImageOptimizer`. It returns the original
        // file untouched whenever it cannot make it meaningfully smaller, so this is the
        // one line that shrinks every public image the platform stores, from every
        // client, without any of them having to cooperate.
        $file = ImageOptimizer::optimise($file, ImageOptimizer::PUBLIC_MAX_EDGE);

        // Batch 67 — refuse before anything is written; then take the camera's GPS block
        // (and every other metadata block) out of the picture. See the two helpers.
        $name = self::filename($file, self::SAFE_IMAGE_EXTENSIONS);
        $file = self::withoutMetadata($file);

        $disk = self::disk();

        // `putFileAs` returns false on a failed write, and the `public` disk is
        // configured with `throw => false`. Without this check a storage failure is
        // invisible and the caller saves a URL for a file that was never written.
        $stored = Storage::disk($disk)->putFileAs($folder, $file, $name, 'public');

        if ($stored === false) {
            Log::error('upload failed', ['disk' => $disk, 'folder' => $folder, 'name' => $name]);

            throw new RuntimeException("Could not write {$folder}/{$name} to the {$disk} disk.");
        }

        return $stored;
    }

    /** @return string the stored relative path (not a URL — the disk is not web-accessible). */
    public static function storePrivate(UploadedFile $file, string $folder): string
    {
        // A larger cap than the public one: nobody looks at a product photo for detail,
        // and everybody looks at a payment proof for a reference number. A PDF is not an
        // image and passes straight through.
        $file = ImageOptimizer::optimise($file, ImageOptimizer::PRIVATE_MAX_EDGE);
        $name = self::filename($file, self::SAFE_DOCUMENT_EXTENSIONS);
        $file = self::withoutMetadata($file);

        return $file->storeAs($folder, $name, 'local');
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

        // Doc 71 — a library file is never deleted by whoever stopped using it, and neither
        // is a file another field still shows. Replacing a product's picture must not take
        // a shared one from the next product.
        if (MediaFiles::isProtected($disk, $path, $url)) {
            return;
        }

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

        // Batch 67 — refused, not renamed. Anything outside the list used to be stored as
        // `<uuid>.bin`: an SVG (which Laravel 11's `image` rule accepts, and which is a
        // document that runs script), an HTML file, anything. On a host where LiteSpeed
        // does not honour the `public/storage` symlink, `/storage/{path}` then served it
        // with the type sniffed from its bytes — `image/svg+xml`, from the API's origin.
        if (! in_array($extension, $allowed, true)) {
            throw new ApiException(
                'unsupported_file_type',
                'نوع الملف غير مدعوم — ارفع صورة JPG أو PNG أو WEBP',
                422,
                errors: ['file' => ['نوع الملف غير مدعوم']],
            );
        }

        return Str::uuid()->toString().'.'.$extension;
    }

    /**
     * Batch 67 — no picture leaves this server carrying where it was taken.
     *
     * {@see ImageMetadata} removes EXIF/XMP/IPTC blocks without touching the pixels. A
     * JPEG whose EXIF says it is rotated is first redrawn upright
     * ({@see ImageOptimizer::upright()}), because the rotation lives in the very block
     * being removed. Anything that is not a JPEG, PNG or WebP (a PDF, a GIF) is returned
     * as it came.
     */
    private static function withoutMetadata(UploadedFile $file): UploadedFile
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_writable($path)) {
            return $file;
        }

        $result = ImageMetadata::strip($path);

        if ($result['orientation'] !== 1) {
            $upright = ImageOptimizer::upright($file, $result['orientation']);

            if ($upright !== null) {
                return $upright;
            }

            ImageMetadata::strip($path, force: true);
        }

        return $file;
    }
}
