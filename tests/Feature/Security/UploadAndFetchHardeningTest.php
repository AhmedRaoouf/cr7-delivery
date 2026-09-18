<?php

namespace Tests\Feature\Security;

use App\Support\FileUploader;
use App\Support\PdfPages;
use App\Support\Security\ImageMetadata;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 67 — the files people hand the server, and the URLs it fetches for them.
 */
class UploadAndFetchHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.public_uploads', 'public');
        Storage::fake('public');
        Storage::fake('local');
        Cache::flush();
    }

    public function test_an_svg_is_refused_wherever_an_image_is_accepted(): void
    {
        $this->seedLocation();
        $this->seed(SettingsSeeder::class);
        Sanctum::actingAs($this->makeCustomer());

        // Laravel 11's `image` rule accepts SVG, and an SVG is a document that runs
        // script. It used to be stored under a `.bin` name — served, on a deployment
        // whose symlink LiteSpeed ignores, by the `/storage/{path}` route with the type
        // sniffed from its bytes: `image/svg+xml`, on the API's own origin.
        $svg = UploadedFile::fake()->createWithContent(
            'receipt.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.domain)"/>',
        );

        $this->withHeaders(['X-App' => 'app'])->post('/api/v1/complaints', [
            'category' => 'app_problem',
            'description' => 'صورة',
            'attachments' => [$svg],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_uploader_itself_refuses_what_is_not_a_safe_image(): void
    {
        $svg = UploadedFile::fake()->createWithContent('x.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        try {
            FileUploader::storePublic($svg, 'complaints');
            $this->fail('An SVG was stored.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame('unsupported_file_type', $e->errorCode);
            $this->assertSame(422, $e->status);
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_photo_loses_its_gps_block_before_anyone_can_download_it(): void
    {
        $path = $this->jpegWithExif(40, 20, orientation: 1);
        $this->assertTrue(ImageMetadata::hasMetadata($path));

        $url = FileUploader::storePublic(new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true), 'complaints');

        $stored = Storage::disk('public')->get('complaints/'.basename($url));
        $this->assertStringNotContainsString("Exif\0\0", $stored);
        $this->assertSame([40, 20], array_slice(getimagesizefromstring($stored), 0, 2));
    }

    public function test_a_rotated_photo_is_stored_upright_rather_than_on_its_side(): void
    {
        // Orientation 6 = «rotate 90° clockwise to display». Stripping the block without
        // baking the rotation in first would publish the picture sideways.
        $path = $this->jpegWithExif(40, 20, orientation: 6);

        $url = FileUploader::storePublic(new UploadedFile($path, 'IMG_0002.jpg', 'image/jpeg', null, true), 'complaints');

        $stored = Storage::disk('public')->get('complaints/'.basename($url));
        $this->assertStringNotContainsString("Exif\0\0", $stored);
        $this->assertSame([20, 40], array_slice(getimagesizefromstring($stored), 0, 2));
    }

    public function test_the_storage_route_serves_pictures_and_nothing_else(): void
    {
        Storage::disk('public')->put('complaints/evil.bin', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        Storage::disk('public')->put('complaints/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        Storage::disk('public')->put('complaints/page.html', '<script>alert(1)</script>');

        $this->get('/storage/complaints/evil.bin')->assertNotFound();
        $this->get('/storage/complaints/evil.svg')->assertNotFound();
        $this->get('/storage/complaints/page.html')->assertNotFound();

        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);
        Storage::disk('public')->put('products/dish.jpg', (string) ob_get_clean());

        $response = $this->get('/storage/products/dish.jpg')->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_file_named_pdf_is_only_read_as_a_pdf(): void
    {
        // ImageMagick picks the decoder from the bytes, not the name. An MVG script named
        // `menu.pdf` can draw `text:/path/to/.env` into a "menu page" — ImageTragick.
        $path = tempnam(sys_get_temp_dir(), 'cr7').'.pdf';
        file_put_contents($path, "push graphic-context\nviewbox 0 0 640 480\nimage over 0,0 0,0 'text:/etc/passwd'\npop graphic-context\n");

        try {
            PdfPages::rasterise(new UploadedFile($path, 'menu.pdf', 'application/pdf', null, true));
            $this->fail('A non-PDF was handed to ImageMagick.');
        } catch (\RuntimeException $e) {
            $this->assertSame('pdf_unreadable', $e->getMessage());
        }
    }

    public function test_a_map_link_is_never_followed_off_the_map_hosts(): void
    {
        // google.com runs an open redirector. The first URL passed the host check, and
        // Guzzle then followed the redirect wherever it pointed.
        Http::fake([
            'https://www.google.com/url*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:3306/']),
            '*' => Http::response('should never be asked', 200),
        ]);

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://www.google.com/url?q=http://127.0.0.1:3306/'))
            ->assertStatus(422);

        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '127.0.0.1'));
        Http::assertSentCount(1);
    }

    public function test_a_map_link_that_stays_on_the_map_hosts_still_resolves(): void
    {
        Http::fake([
            'https://maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com.eg/maps/place/CR7/@31.4400,31.5200,17z/data=!3d31.4456!4d31.5289',
            ]),
            'https://www.google.com.eg/*' => Http::response('<html></html>', 200),
        ]);

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://maps.app.goo.gl/AbCdEfGh'))
            ->assertOk()
            ->assertJsonPath('data.lat', 31.4456)
            ->assertJsonPath('data.lng', 31.5289);
    }

    public function test_a_link_with_credentials_or_an_odd_port_is_refused_unfetched(): void
    {
        Http::fake();

        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://evil.example@www.google.com/maps'))->assertStatus(422);
        $this->getJson('/api/v1/geo/expand-link?url='.urlencode('https://www.google.com:8443/maps'))->assertStatus(422);

        Http::assertNothingSent();
    }

    /** A real JPEG carrying an EXIF block with an Orientation and a GPS pointer. */
    private function jpegWithExif(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, (int) imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // TIFF, little-endian: IFD0 with Orientation (0x0112) and a GPS IFD pointer
        // (0x8825); the GPS IFD holds GPSLatitudeRef = "N".
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 2)
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            .pack('vvVV', 0x8825, 4, 1, 38)
            .pack('V', 0)
            .pack('v', 1)
            .pack('vvVa4', 0x0001, 2, 2, "N\0\0\0")
            .pack('V', 0);

        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        $path = tempnam(sys_get_temp_dir(), 'cr7exif').'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return $path;
    }
}
