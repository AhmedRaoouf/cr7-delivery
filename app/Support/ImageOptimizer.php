<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Shrink an uploaded image before it is written, without a visible change to it.
 *
 * Every image this platform stores arrives from a phone camera or a laptop screenshot
 * and is displayed at a few hundred pixels: a product row is 96 px, a restaurant hero is
 * 390 px wide, a captain's avatar is 48. A 4 000 × 3 000 photo carries roughly forty
 * times the pixels anything ever asks for, and the server pays for all of them twice —
 * once in disk, once in the bandwidth of every screen that loads it.
 *
 * So the saving comes from **resolution, not from quality**. Resampling to a cap the UI
 * never exceeds is invisible by construction; only after that does the encoder matter,
 * and JPEG at 85 is the setting where the difference stops being findable. Two rules
 * keep it honest:
 *
 *  - **A lossy image that does not need resizing is not touched.** Re-encoding a JPEG
 *    that is already the right size is generational loss for nothing.
 *  - **The result is kept only when it is meaningfully smaller.** If the original was
 *    already well encoded, it is stored exactly as it arrived.
 *
 * PNG stays PNG and is only recompressed — lossless, alpha intact. Converting it to JPEG
 * would ring around the hard edges of a logo, and doc 06 §6 says a restaurant's logo
 * ships as supplied.
 *
 * Failure is never fatal: anything unexpected returns the original file, because a
 * captain who cannot upload a licence is a worse outcome than a large licence.
 */
final class ImageOptimizer
{
    /** Longest edge for a public image. `CUS-14`'s hero is 390 px at 3×; 1600 is headroom. */
    public const PUBLIC_MAX_EDGE = 1600;

    /**
     * Longest edge for a private one. Payment proofs and ID cards are read by a human
     * looking for a reference number, so they keep more than the display sizes need.
     */
    public const PRIVATE_MAX_EDGE = 2200;

    private const JPEG_QUALITY = 85;

    private const WEBP_QUALITY = 85;

    /** Re-encode an already-small lossy image only above this — a q100 export is waste. */
    private const REENCODE_ABOVE_BYTES = 1_500_000;

    /** Keep the result only if it saves at least this share of the original. */
    private const MIN_SAVING = 0.10;

    /**
     * Decode budget: GD holds every pixel as 4 bytes, so 40 MP is ~160 MB. Past this the
     * process would be OOM-killed mid-request; the original is stored instead.
     */
    private const MAX_PIXELS = 40_000_000;

    /**
     * @return UploadedFile the optimised file, or `$file` itself when it cannot be
     *                      improved — the caller stores whatever comes back and never
     *                      needs to know which happened.
     */
    public static function optimise(UploadedFile $file, int $maxEdge = self::PUBLIC_MAX_EDGE): UploadedFile
    {
        try {
            return self::run($file, $maxEdge) ?? $file;
        } catch (\Throwable $e) {
            Log::warning('image optimisation skipped', [
                'name' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return $file;
        }
    }

    private static function run(UploadedFile $file, int $maxEdge): ?UploadedFile
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;
        $type = $info[2] ?? null;

        // GIF is skipped because GD would keep only the first frame of an animation, and
        // AVIF because it is already smaller than anything produced here.
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        if ($width * $height > self::MAX_PIXELS) {
            Log::info('image too large to optimise in memory', ['pixels' => $width * $height]);

            return null;
        }

        $bytes = (int) filesize($path);
        $scale = min(1.0, $maxEdge / max($width, $height));
        $needsResize = $scale < 1.0;
        $isLossy = $type === IMAGETYPE_JPEG || $type === IMAGETYPE_WEBP;

        // Nothing to gain: it already fits, and re-encoding a lossy image at this size
        // would only spend quality.
        if (! $needsResize && $isLossy && $bytes <= self::REENCODE_ABOVE_BYTES) {
            return null;
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            return null;
        }

        try {
            // A phone writes the sensor's raw orientation and a separate EXIF tag saying
            // how to turn it. GD does not read that tag and does not write it back, so
            // re-encoding without applying it first is how an upright photo becomes a
            // sideways one — the one way this class could visibly damage an image.
            $image = self::applyExifOrientation($image, $path, $type);

            // A 90° rotation swaps them, and the resample below reads the real canvas,
            // not the numbers `getimagesize` reported before the turn.
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1.0, $maxEdge / max($width, $height));
            $needsResize = $scale < 1.0;

            if ($needsResize) {
                // `imagecopyresampled`, not `imagescale`: the latter's better filters are
                // compiled into libgd optionally and `IMG_BICUBIC_FIXED` returns `false`
                // on a build without them — silently, which here meant every oversized
                // image was stored at full size and nothing said so.
                $image = self::resample($image, $width, $height, $scale, $type);

                if ($image === null) {
                    return null;
                }
            }

            $target = tempnam(sys_get_temp_dir(), 'cr7img');

            if ($target === false) {
                return null;
            }

            $written = match ($type) {
                // Alpha is only preserved when it is both saved and left un-blended.
                IMAGETYPE_PNG => imagealphablending($image, false)
                    && imagesavealpha($image, true)
                    && imagepng($image, $target, 9),
                IMAGETYPE_WEBP => imagealphablending($image, false)
                    && imagesavealpha($image, true)
                    && imagewebp($image, $target, self::WEBP_QUALITY),
                default => imagejpeg($image, $target, self::JPEG_QUALITY),
            };

            $after = $written ? (int) filesize($target) : 0;

            if (! $written || $after === 0 || $after > $bytes * (1 - self::MIN_SAVING)) {
                @unlink($target);

                return null;
            }

            // `test: true` — the file was produced here, not posted by a client, so the
            // upload-error and size checks that guard a real `$_FILES` entry would
            // otherwise reject it. The name carries the original so a stored filename
            // derived from it still reads sensibly; the extension is re-derived from the
            // bytes by `FileUploader` regardless.
            return new UploadedFile(
                $target,
                $file->getClientOriginalName(),
                $file->getMimeType(),
                null,
                true,
            );
        } finally {
            // No `imagedestroy`: GD handles have been refcounted objects since PHP 8.0,
            // the call is a no-op, and 8.5 deprecates it.
            unset($image);
        }
    }

    /** @return \GdImage|null the resampled image, or null when GD refuses the copy. */
    private static function resample(\GdImage $image, int $width, int $height, float $scale, ?int $type): ?\GdImage
    {
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            return null;
        }

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            // A fresh truecolor canvas is opaque black. Without this the transparent
            // parts of a logo are resampled onto it and the mark ends up in a box.
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }

        $copied = imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $copied ? $canvas : null;
    }

    private static function applyExifOrientation(\GdImage $image, string $path, ?int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated === false) {
            return $image;
        }

        if ($rotated !== $image) {
            imagedestroy($image);
            $image = $rotated;
        }

        // 2, 4, 5 and 7 are the mirrored orientations: the rotation above puts them the
        // right way up but back to front.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }
}
