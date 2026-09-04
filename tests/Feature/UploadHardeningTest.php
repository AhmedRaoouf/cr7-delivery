<?php

namespace Tests\Feature;

use App\Support\FileUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Three defects met on the deployed API, in one place.
 *
 * The one that mattered most did not look like a defect at all: an upload answered
 * `200 {"success":true}` with a `/storage/...` URL, and that URL answered 404 — the
 * `public` disk serves through a symlink Hostinger's shared hosting does not have.
 * A silent failure that reads as success is worse than an error, so `storePublic`
 * now raises when the write does not land, and the disk is configurable so the
 * deployment can point at R2 instead.
 */
class UploadHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.public_uploads', 'public');
        Storage::fake('public');
    }

    public function test_the_stored_extension_comes_from_the_bytes_not_the_filename(): void
    {
        // Real PNG bytes on disk, presented under a name ending in `.php`. The old
        // `getClientOriginalExtension()` read the name and wrote an executable path
        // into a web-served directory; validation never caught it, because Laravel's
        // `image` rule inspects the MIME type and the MIME type is genuinely PNG.
        $path = tempnam(sys_get_temp_dir(), 'cr7').'.png';
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);
        imagedestroy($image);

        $upload = new UploadedFile($path, 'payload.php', 'image/png', null, true);

        $url = FileUploader::storePublic($upload, 'products');

        $this->assertStringEndsNotWith('.php', $url);
        $this->assertMatchesRegularExpression('/\.(png|jpg|jpeg|webp|gif|avif|bin)$/', $url);
    }

    public function test_an_uploaded_image_is_actually_written_to_the_disk(): void
    {
        $url = FileUploader::storePublic(UploadedFile::fake()->image('logo.png'), 'restaurants');

        $stored = Storage::disk('public')->allFiles('restaurants');

        $this->assertCount(1, $stored);
        $this->assertStringContainsString(basename($stored[0]), $url);
    }

    public function test_the_upload_disk_follows_the_configured_public_disk(): void
    {
        Storage::fake('r2');
        config()->set('filesystems.public_uploads', 'r2');

        FileUploader::storePublic(UploadedFile::fake()->image('logo.png'), 'restaurants');

        $this->assertCount(1, Storage::disk('r2')->allFiles('restaurants'));
        $this->assertCount(0, Storage::disk('public')->allFiles('restaurants'));
    }

    public function test_deleting_removes_a_file_this_application_stored(): void
    {
        $url = FileUploader::storePublic(UploadedFile::fake()->image('logo.png'), 'restaurants');
        $this->assertCount(1, Storage::disk('public')->allFiles('restaurants'));

        FileUploader::deletePublic($url);

        $this->assertCount(0, Storage::disk('public')->allFiles('restaurants'));
    }

    public function test_deleting_ignores_a_url_from_somewhere_else(): void
    {
        // Seeded rows carry `/catalog/...` URLs for files this application never wrote.
        // Removing such an image must clear the column without touching anything.
        FileUploader::storePublic(UploadedFile::fake()->image('logo.png'), 'restaurants');

        FileUploader::deletePublic('https://cdn.example.com/catalog/restaurants/seeded.png');
        FileUploader::deletePublic(null);
        FileUploader::deletePublic('');

        $this->assertCount(1, Storage::disk('public')->allFiles('restaurants'));
    }
}
