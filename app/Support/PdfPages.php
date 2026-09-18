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
        /*
         * Batch 67 — only a PDF is ever handed to ImageMagick, and only as a PDF.
         *
         * ImageMagick chooses its decoder from the file's **bytes**, not its name: an MVG
         * or MSL script uploaded as `menu.pdf` runs through the vector-drawing coders,
         * which can read local files into the picture (`text:/path/.env` — the
         * ImageTragick family). The `mimes:…,pdf` rule already sniffs the content; this
         * is the second lock, at the only place the bytes meet ImageMagick: the file must
         * start with a PDF header, and it is read through the `pdf:` coder explicitly so
         * nothing else can claim it.
         */
        $path = (string) $pdf->getRealPath();
        $head = @file_get_contents($path, false, null, 0, 1024);

        if (! is_string($head) || ! str_contains($head, '%PDF-')) {
            throw new RuntimeException('pdf_unreadable');
        }

        if (! self::isSupported()) {
            throw new RuntimeException('pdf_unsupported');
        }

        $imagick = new Imagick;

        try {
            // A menu is a handful of pages; a crafted file must not be able to take the
            // host's memory or its disk with it.
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);

            // Before readImage, not after: the resolution is what Ghostscript
            // rasterises *at*, so setting it afterwards only resamples an image
            // that was already rendered at the default ‪72‬ dpi and the pages come
            // out soft no matter what number is used.
            $imagick->setResolution(self::DPI, self::DPI);
            $imagick->readImage('pdf:'.$path);
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
            // 🔴 **Indexed, and never `foreach ($imagick as $page)`.**
            //
            // `mergeImageLayers()` **appends its result to the sequence it was
            // called on**, and Imagick's iterator walks whatever the sequence
            // holds *now*. So flattening inside a `foreach` adds a fourth page,
            // then reads it, flattens it, adds a fifth… A three-page PDF ran
            // past ‪19‬ pages and was still going when it was killed: an upload
            // that never returns, filling the disk with identical JPEGs.
            //
            // It was invisible locally — `imagick` is not installed on the
            // development machine, so the test skips there — and it was only
            // found by running the rasteriser on the deployment. `getImage()`
            // returns a **copy holding one image**, so the merge below has its
            // own sequence to append to and the original is never touched.
            for ($index = 0; $index < $count; $index++) {
                $imagick->setIteratorIndex($index);
                $page = $imagick->getImage();

                // A PDF page has no background of its own, and a transparent one
                // flattens to black in most viewers — a menu nobody can read.
                $page->setImageBackgroundColor('white');
                $flattened = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $page->clear();

                $flattened->setImageFormat('jpeg');
                $flattened->setImageCompressionQuality(88);

                $path = tempnam(sys_get_temp_dir(), 'menu-page-').'.jpg';
                $flattened->writeImage($path);
                $flattened->clear();

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
