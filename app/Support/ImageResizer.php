<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * «في التطبيق بياخد وقت كبير اوي علي ما يحمل الصور» — operator, 2026-09-09.
 *
 * The app was already decoding at the size it draws (`AppImage`'s
 * `memCacheWidth`), which fixes memory and never fixes the wire. What crossed
 * the wire was the original: a restaurant logo drawn in a 42 dp circle was a
 * **512 × 512 PNG of 370 KB**, and a cuisine chip was an 800 × 800 JPEG of
 * 220 KB. A cold home screen pulled roughly 3.5 MB in about thirty requests off
 * shared hosting with a 0.4–1.0 s time to first byte.
 *
 * So the size is decided before the bytes are sent.
 *
 * **It writes what it makes.** The resized file is stored under
 * `public/img/{width}/…` at the same path, so the *next* request for it is
 * answered by the web server off disk and never reaches PHP at all. One CPU
 * cost per image per width, on a host that has no CDN in front of it and cannot
 * afford one per request.
 *
 * **Widths are an allowlist, not a parameter.** A free-form `?w=` is a cache
 * the caller controls: 900 distinct widths is 900 stored copies of every logo
 * on a host with a disk quota. Five buckets cover every slot the app draws.
 */
class ImageResizer
{
    /** The only widths that can be asked for. */
    public const WIDTHS = [96, 192, 384, 768, 1200];

    /** The width every list thumbnail is asked for (a grid cell, a gallery strip). */
    public const THUMB_WIDTH = 384;

    /**
     * Where a resize may read from. Everything else is a 404.
     *
     * The first three are folders under `public/`; `storage` is the **uploads disk** — what
     * a place or an admin uploaded, which lives outside `public/` and is served at
     * `/storage/…` — and it is read through the disk, never by a path the caller composes.
     * Batch 72: the media library's pictures are uploads, so its thumbnails come from here.
     */
    private const ROOTS = ['catalog', 'media/system', 'shipped', 'storage'];

    /** The upload disk's folder, as it appears in a resize path (`/img/384/storage/…`). */
    public const DISK_ROOT = 'storage';

    /** What `/img/{width}/{path}` accepts as `{path}` — the route and `ThumbUrl` share it. */
    public const ROUTE_PATTERN = '#^(?:catalog|media/system|shipped|storage)/[A-Za-z0-9/_\-\.]+$#';

    /** The only source types a resize can decode; anything else is answered as itself. */
    public const RESIZABLE = ['jpg', 'jpeg', 'png', 'webp'];

    /** What a resize path may end in at all — a narrow GIF or AVIF is still served as itself. */
    public const SERVABLE = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    public const CACHE_ROOT = 'img';

    /** The bucket that covers a slot without ever handing it fewer pixels than it needs. */
    public static function bucketFor(int $width): int
    {
        foreach (self::WIDTHS as $candidate) {
            if ($candidate >= $width) {
                return $candidate;
            }
        }

        return (int) end(self::WIDTHS);
    }

    /**
     * Resize the picture `$path` names — a file under `public/` or on the uploads disk, see
     * [ROOTS] — to `$width` and return the file that holds it, or null when there is
     * nothing to resize.
     *
     * Returns the **original** when it is already narrower than the bucket:
     * upscaling a 256 px icon to 384 spends bytes to lose sharpness.
     */
    public static function make(string $path, int $width): ?string
    {
        if (! in_array($width, self::WIDTHS, true) || ! self::isSafe($path)) {
            return null;
        }

        $source = self::source($path);

        if ($source === null) {
            return null;
        }

        $cached = public_path(self::CACHE_ROOT."/{$width}/{$path}");

        if (is_file($cached) && filemtime($cached) >= filemtime($source)) {
            return $cached;
        }

        $size = @getimagesize($source);

        if ($size === false) {
            return null;
        }

        [$sourceWidth, $sourceHeight] = $size;

        if ($sourceWidth <= $width) {
            return $source;
        }

        $image = self::read($source, $size['mime'] ?? '');

        if ($image === null) {
            return null;
        }

        $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
        $resized = imagecreatetruecolor($width, $height);

        // PNG logos are cut-outs. Without these three lines every one of them
        // comes back on a black square, which is worse than a slow image.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        imagedestroy($image);

        @mkdir(dirname($cached), 0755, true);
        self::write($resized, $cached, $size['mime'] ?? '');
        imagedestroy($resized);

        return is_file($cached) ? $cached : null;
    }

    /**
     * The local file a resize path names, or null when there is none (or it escapes its root).
     *
     * A `storage/…` path is resolved through the uploads disk, and only when that disk keeps
     * its files on this machine; a remote disk has nothing to decode here, so the caller
     * falls back to the original URL.
     */
    public static function source(string $path): ?string
    {
        // A picture, by its name: nothing else under these roots is ever read (a text file
        // on the uploads disk, a PHP file that shares a folder with the art).
        if (! self::isSafe($path) || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SERVABLE, true)) {
            return null;
        }

        if (! str_starts_with($path, self::DISK_ROOT.'/')) {
            $file = public_path($path);

            return is_file($file) ? $file : null;
        }

        $disk = Storage::disk(FileUploader::disk());
        $relative = substr($path, strlen(self::DISK_ROOT) + 1);

        if (! method_exists($disk, 'path') || ! $disk->exists($relative)) {
            return null;
        }

        // Absolute, a regular file, and still inside the disk's root — a symlink or a stray
        // `..` that survived the string checks in `isSafe()` ends here.
        $real = realpath($disk->path($relative));
        $root = realpath($disk->path(''));

        if ($real === false || $root === false || ! is_file($real) || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * The path may only name a file inside [ROOTS].
     *
     * Checked as a **string** rather than by resolving it, because
     * `realpath()` on a path that does not exist returns false and the caller
     * would then fall through to «no such image» for a request that was in fact
     * an escape attempt. Rejecting the shape is unambiguous.
     */
    private static function isSafe(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        if (str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }

        foreach (self::ROOTS as $root) {
            if (str_starts_with($path, $root.'/') && strlen($path) > strlen($root) + 1) {
                return true;
            }
        }

        return false;
    }

    /** @return \GdImage|null */
    private static function read(string $source, string $mime)
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
            default => false,
        };

        return $image === false ? null : $image;
    }

    private static function write(\GdImage $image, string $target, string $mime): void
    {
        match ($mime) {
            // 82 is where a photograph stops getting visibly better and keeps
            // getting bigger. A logo goes back out as a PNG so its transparency
            // survives; 9 is compression level, not quality — PNG is lossless.
            'image/jpeg' => imagejpeg($image, $target, 82),
            'image/png' => imagepng($image, $target, 9),
            'image/webp' => imagewebp($image, $target, 82),
            default => null,
        };
    }

    /** Drop every stored size of one source — call it when the source changes. */
    public static function forget(string $path): void
    {
        foreach (self::WIDTHS as $width) {
            $cached = public_path(self::CACHE_ROOT."/{$width}/{$path}");

            if (is_file($cached)) {
                @unlink($cached);
            }
        }
    }
}
