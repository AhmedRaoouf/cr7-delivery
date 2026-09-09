<?php

namespace Tests\Feature;

use App\Support\ImageResizer;
use Tests\TestCase;

/**
 * «في التطبيق بياخد وقت كبير اوي علي ما يحمل الصور» — operator, 2026-09-09.
 *
 * `/img/{width}/catalog/…` is what stopped a 42 dp logo costing 370 KB. Two
 * things are worth a test and nothing else is: that it cannot be turned into a
 * reader for files outside the catalogue, and that the widths it answers are
 * the widths the app asks for.
 */
class ResizedImageTest extends TestCase
{
    private string $source = 'catalog/restaurants/__resizer-test.png';

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(public_path('catalog/restaurants'), 0755, true);
        $image = imagecreatetruecolor(600, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        imagepng($image, public_path($this->source));
        imagedestroy($image);
    }

    protected function tearDown(): void
    {
        @unlink(public_path($this->source));
        ImageResizer::forget($this->source);

        parent::tearDown();
    }

    public function test_it_answers_the_picture_at_the_width_asked_for(): void
    {
        $response = $this->get("/img/192/{$this->source}");

        $response->assertOk();

        // `response()->file()` is a `BinaryFileResponse`, so the bytes are the
        // file it points at rather than a body to read.
        [$width] = getimagesize($response->baseResponse->getFile()->getPathname());

        $this->assertSame(192, $width);
    }

    public function test_the_second_request_is_a_file_on_disk(): void
    {
        // The whole design: PHP resizes once, the web server serves it forever.
        $this->get("/img/384/{$this->source}")->assertOk();

        $this->assertFileExists(
            public_path(ImageResizer::CACHE_ROOT."/384/{$this->source}"),
        );
    }

    public function test_a_source_narrower_than_the_bucket_is_left_alone(): void
    {
        // Upscaling spends bytes to lose sharpness.
        $response = $this->get("/img/1200/{$this->source}");

        $response->assertOk();
        $this->assertFileDoesNotExist(
            public_path(ImageResizer::CACHE_ROOT."/1200/{$this->source}"),
        );
    }

    public function test_the_widths_are_an_allowlist(): void
    {
        // A free-form width is a cache the caller controls — 900 distinct
        // values is 900 stored copies of every logo on a host with a quota.
        $this->get("/img/900/{$this->source}")->assertNotFound();
        $this->get("/img/193/{$this->source}")->assertNotFound();
    }

    public function test_the_app_and_the_server_agree_on_the_buckets(): void
    {
        // `RemoteImageSize.widths` in the Flutter app is this list. A width the
        // app asks for and the router does not know is a 404 on every image.
        $this->assertSame([96, 192, 384, 768, 1200], ImageResizer::WIDTHS);
    }

    public function test_it_cannot_read_outside_the_catalogue(): void
    {
        foreach ([
            'img/192/../../.env',
            'img/192/catalog/../../.env',
            'img/192/storage/private/id-card.jpg',
            'img/192/index.php',
        ] as $attempt) {
            $this->get('/'.$attempt)->assertNotFound();
        }

        $this->assertNull(ImageResizer::make('../.env', 192));
        $this->assertNull(ImageResizer::make('/etc/passwd', 192));
        $this->assertNull(ImageResizer::make('catalog/../../.env', 192));
    }
}
