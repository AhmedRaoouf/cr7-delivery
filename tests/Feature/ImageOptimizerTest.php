<?php

namespace Tests\Feature;

use App\Support\FileUploader;
use App\Support\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every image the platform stores arrives at camera resolution and is displayed at a few
 * hundred pixels. The optimiser trades the pixels nothing asks for; these tests fix the
 * two halves of «without touching the quality»: it must shrink what is oversized, and it
 * must leave alone anything it cannot improve.
 */
class ImageOptimizerTest extends TestCase
{
    /** A photograph-like JPEG — noise, so the encoder cannot cheat with flat colour. */
    private function photo(int $width, int $height, int $quality = 100): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 4) {
            for ($y = 0; $y < $height; $y += 4) {
                $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $colour);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'src').'.jpg';
        imagejpeg($image, $path, $quality);
        imagedestroy($image);

        return $path;
    }

    private function transparentPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocatealpha($image, 255, 200, 0, 127));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), 200, 200, imagecolorallocate($image, 0, 0, 0));

        $path = tempnam(sys_get_temp_dir(), 'src').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    public function test_an_oversized_photo_is_capped_at_the_public_edge(): void
    {
        $path = $this->photo(3600, 2400);
        $before = filesize($path);

        $result = ImageOptimizer::optimise(new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true));

        [$width, $height] = getimagesize($result->getRealPath());

        $this->assertSame(ImageOptimizer::PUBLIC_MAX_EDGE, max($width, $height));
        $this->assertSame(2, (int) round($width / $height * 1.5), 'the aspect ratio is preserved');
        $this->assertLessThan($before, filesize($result->getRealPath()));
    }

    public function test_a_private_document_keeps_a_higher_cap(): void
    {
        $path = $this->photo(3600, 2400);

        $result = ImageOptimizer::optimise(
            new UploadedFile($path, 'id.jpg', 'image/jpeg', null, true),
            ImageOptimizer::PRIVATE_MAX_EDGE,
        );

        [$width] = getimagesize($result->getRealPath());

        $this->assertSame(ImageOptimizer::PRIVATE_MAX_EDGE, $width);
    }

    public function test_an_image_that_is_already_small_is_returned_untouched(): void
    {
        // Well-encoded and already inside the cap: re-encoding it would spend quality
        // for nothing, and the optimiser must hand back the very same file.
        $path = $this->photo(600, 400, 80);
        $file = new UploadedFile($path, 'small.jpg', 'image/jpeg', null, true);

        $result = ImageOptimizer::optimise($file);

        $this->assertSame($file, $result);
        $this->assertSame(file_get_contents($path), file_get_contents($result->getRealPath()));
    }

    public function test_a_pdf_passes_straight_through(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'doc').'.pdf';
        file_put_contents($path, "%PDF-1.4\n".str_repeat('x', 4096));
        $file = new UploadedFile($path, 'licence.pdf', 'application/pdf', null, true);

        $this->assertSame($file, ImageOptimizer::optimise($file, ImageOptimizer::PRIVATE_MAX_EDGE));
    }

    public function test_a_logo_keeps_its_transparency(): void
    {
        // doc 06 §6 — a restaurant's logo ships as supplied. Shrinking it may not flatten
        // the alpha channel onto a background this code invented.
        $path = $this->transparentPng(2400, 2400);

        $result = ImageOptimizer::optimise(new UploadedFile($path, 'logo.png', 'image/png', null, true));

        $optimised = imagecreatefrompng($result->getRealPath());
        $corner = imagecolorat($optimised, 2, 2);
        $alpha = ($corner >> 24) & 0x7F;
        imagedestroy($optimised);

        $this->assertSame(IMAGETYPE_PNG, getimagesize($result->getRealPath())[2], 'it stays a PNG');
        $this->assertGreaterThan(100, $alpha, 'the transparent corner is still transparent');
    }

    public function test_exif_orientation_is_applied_before_the_tag_is_dropped(): void
    {
        // GD neither reads nor writes the EXIF orientation tag, so a portrait photo from
        // a phone would come back on its side unless the rotation is baked in first.
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('the exif extension is not loaded');
        }

        $path = $this->photo(3000, 2000);
        $this->stampOrientation($path, 6);

        $result = ImageOptimizer::optimise(new UploadedFile($path, 'portrait.jpg', 'image/jpeg', null, true));
        [$width, $height] = getimagesize($result->getRealPath());

        $this->assertLessThan($height, $width, 'orientation 6 means the stored pixels are rotated 90°');
    }

    public function test_the_uploader_stores_the_optimised_bytes(): void
    {
        Storage::fake('public');
        config(['filesystems.public_uploads' => 'public']);

        $path = $this->photo(3600, 2400);
        $before = filesize($path);

        $url = FileUploader::storePublic(new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true), 'products');
        $stored = ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        $stored = preg_replace('#^storage/#', '', $stored);

        $this->assertLessThan($before, Storage::disk('public')->size($stored));
        $this->assertSame(
            ImageOptimizer::PUBLIC_MAX_EDGE,
            getimagesizefromstring(Storage::disk('public')->get($stored))[0],
        );
    }

    /** Writes a minimal EXIF APP1 segment carrying one Orientation tag. */
    private function stampOrientation(string $path, int $orientation): void
    {
        $ifd = pack('v', 1)                                  // one entry
            .pack('v', 0x0112).pack('v', 3).pack('V', 1)     // Orientation, SHORT, count 1
            .pack('v', $orientation).pack('v', 0)
            .pack('V', 0);                                   // no next IFD

        $tiff = "MM\x00\x2a".pack('N', 8);
        // Big-endian byte order marker, so rewrite the little-endian packs above.
        $ifd = pack('n', 1)
            .pack('n', 0x0112).pack('n', 3).pack('N', 1)
            .pack('n', $orientation).pack('n', 0)
            .pack('N', 0);

        $app1 = "Exif\x00\x00".$tiff.$ifd;
        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        $jpeg = file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
    }
}
