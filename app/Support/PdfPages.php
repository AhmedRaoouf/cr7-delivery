<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Imagick;
use RuntimeException;
use Throwable;

/**
 * A printed menu handed over as one PDF, split into one image per page.
 *
 * Operator, 2026-09-12: «عايز صفحات المنيو في الأماكن تبقى صور أو PDF ويتعرض
 * كصور للصفحات… ولما أدوس على صفحة تفتح full screen وأقلب بالـ swipe بينهم
 * وأقدر أكبّر وأصغّر بسلاسة والجودة تبقى حلوة».
 *
 * The display half of that already existed and has since `CUS-15c`:
 * `PrintedMenuSection` draws the rail, `ImageGallerySheet` opens a page full
 * width with `InteractiveViewer` at up to ×‪4‬ and swipes between pages. What
 * was missing was **any** way to get a sheet in — `restaurants.menu_sheets` had
 * a column, a cast and a seeder, and appeared in zero form requests and zero
 * controllers.
 *
 * So a PDF is turned into pages **here**, on upload, rather than teaching the
 * app to render one. The app gains no dependency, the pages zoom and swipe like
 * every other image on the platform, and the quality is a number in this file
 * rather than a property of whatever viewer a phone happens to ship.
 *
 * Checked on the deployment before this was written: `imagick` is loaded,
 * Ghostscript is at `/usr/bin/gs`, and `Imagick::queryFormats()` lists PDF — so
 * the rasterisation runs on the production host, not only on a developer's
 * machine. Where it does not, {@see self::isSupported()} is false and the
 * request is refused with a sentence that says to upload images instead, which
 * is a thing the operator can actually do.
 */
class PdfPages
{
    /**
     * Dots per inch for the rasterised page.
     *
     * ‪150‬ renders an A4 sheet at about ‪1240‬ × ‪1754‬ — sharp enough to read a
     * price list zoomed in, and still smaller than most of the dish photos the
     * platform already serves. `ImageOptimizer` caps the long edge afterwards.
     */
    private const DPI = 150;

    /**
     * A printed menu is a handful of sheets. A cap exists because a PDF is an
     * arbitrary document and a ‪400‬-page upload should fail as «too many pages»
     * rather than as a request timeout with half the pages written.
     */
    public const MAX_PAGES = 30;

    public static function isSupported(): bool
    {
        return extension_loaded('imagick')
            && in_array('PDF', array_map('strtoupper', Imagick::queryFormats()), true);
    }

    /**
     * @return list<UploadedFile> one temporary PNG per page, in page order
     *
     * @throws RuntimeException when the PDF cannot be read or has too many pages
     */
    public static function rasterise(UploadedFile $pdf): array
    {
        if (! self::isSupported()) {
            throw new RuntimeException('pdf_unsupported');
        }

        $imagick = new Imagick;

        try {
            // Before readImage, not after: the resolution is what Ghostscript
            // rasterises *at*, so setting it afterwards only resamples an image
            // that was already rendered at the default ‪72‬ dpi and the pages come
            // out soft no matter what number is used.
            $imagick->setResolution(self::DPI, self::DPI);
            $imagick->readImage($pdf->getRealPath());
        } catch (Throwable $e) {
            $imagick->clear();

            Log::warning('[menu] could not read an uploaded PDF', ['message' => $e->getMessage()]);

            throw new RuntimeException('pdf_unreadable', previous: $e);
        }

        $count = $imagick->getNumberImages();

        if ($count > self::MAX_PAGES) {
            $imagick->clear();

            throw new RuntimeException('pdf_too_many_pages');
        }

        $pages = [];

        try {
            foreach ($imagick as $index => $page) {
                // A PDF page has no background of its own, and a transparent one
                // flattens to black in most viewers — a menu nobody can read.
                $page->setImageBackgroundColor('white');
                $page = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(88);

                $path = tempnam(sys_get_temp_dir(), 'menu-page-').'.jpg';
                $page->writeImage($path);
                $page->clear();

                $pages[] = new UploadedFile(
                    $path,
                    sprintf('menu-page-%02d.jpg', $index + 1),
                    'image/jpeg',
                    test: true,
                );
            }
        } finally {
            $imagick->clear();
        }

        return $pages;
    }
}
