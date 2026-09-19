<?php

namespace App\Support\Media;

use App\Support\FileUploader;
use App\Support\ImageResizer;
use Illuminate\Support\Facades\Storage;

/**
 * The thumbnail URL of a stored picture (batch 72).
 *
 * A picker grid, the media library and a product's gallery strip draw many pictures at ~120 px
 * and used to download every original — up to 5 MB each. `ImageResizer` already answers
 * `/img/{width}/…` and writes what it makes to `public/img/`, where the web server serves it
 * from disk on every later request; this class only builds the URL, so the mechanism is the
 * one the app's catalogue art has used since 2026-09-09, not a second one.
 *
 * **Always a fetchable URL.** A picture we cannot resize — a remote host, a GIF, a file that
 * is no longer on disk, a URL that is not ours — is answered as itself. The client never has
 * to decide whether a thumbnail exists; `thumb_url` is simply the URL to draw in a small slot.
 *
 * The URL carries `?v=<mtime>` of the source: the response is `immutable` for a year, and the
 * fingerprint is what stops a replaced picture keeping its old thumbnail.
 */
final class ThumbUrl
{
    public static function for(?string $url, int $width = ImageResizer::THUMB_WIDTH): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $path = self::resizePath($url);

        if ($path === null || ! in_array($width, ImageResizer::WIDTHS, true)) {
            return $url;
        }

        $source = ImageResizer::source($path);

        if ($source === null) {
            return $url;
        }

        return rtrim(asset(''), '/')."/img/{$width}/{$path}?v=".(int) @filemtime($source);
    }

    /**
     * `storage/<path>` for a file on the uploads disk, or `<folder>/…` for one under
     * `public/`; null for anything that is not one of ours or not a decodable raster.
     */
    public static function resizePath(string $url): ?string
    {
        $clean = explode('#', explode('?', $url, 2)[0], 2)[0];

        if (! in_array(strtolower(pathinfo($clean, PATHINFO_EXTENSION)), ImageResizer::RESIZABLE, true)) {
            return null;
        }

        $diskBase = rtrim((string) Storage::disk(FileUploader::disk())->url(''), '/');
        $webBase = rtrim(asset(''), '/');
        $path = null;

        if ($diskBase !== '' && str_starts_with($clean, $diskBase.'/')) {
            $path = ImageResizer::DISK_ROOT.'/'.rawurldecode(substr($clean, strlen($diskBase) + 1));
        } elseif (str_starts_with($clean, $webBase.'/')) {
            $path = rawurldecode(substr($clean, strlen($webBase) + 1));
        }

        // Only a path the `/img/{width}/{path}` route will accept: a name with a space or an
        // Arabic letter would be a thumbnail URL that answers 404.
        return $path !== null && preg_match(ImageResizer::ROUTE_PATTERN, $path) === 1 ? $path : null;
    }
}
