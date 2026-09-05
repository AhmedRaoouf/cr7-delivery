<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\HandlesUploads;
use App\Models\Product;
use App\Support\FileUploader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        Storage::fake('elsewhere');
        config()->set('filesystems.public_uploads', 'elsewhere');

        FileUploader::storePublic(UploadedFile::fake()->image('logo.png'), 'restaurants');

        $this->assertCount(1, Storage::disk('elsewhere')->allFiles('restaurants'));
        $this->assertCount(0, Storage::disk('public')->allFiles('restaurants'));
    }

    public function test_a_configured_disk_that_no_longer_exists_falls_back_instead_of_throwing(): void
    {
        // The deployment ran on Cloudflare R2 for a while and its `.env` still carries
        // `PUBLIC_UPLOAD_DISK=r2`. That disk is gone from `config/filesystems.php`, and
        // without this fallback the first upload after the pull throws «Disk [r2] does
        // not have a configured driver» — a leftover env line turning a working feature
        // into a 500, on the one code path where the failure is not a missing image.
        config()->set('filesystems.public_uploads', 'r2');

        $url = FileUploader::storePublic(UploadedFile::fake()->image('logo.png'), 'restaurants');

        $this->assertCount(1, Storage::disk('public')->allFiles('restaurants'));
        $this->assertStringContainsString('restaurants/', $url);
    }

    public function test_deleting_removes_a_file_this_application_stored(): void
    {
        $url = FileUploader::storePublic(UploadedFile::fake()->image('logo.png'), 'restaurants');
        $this->assertCount(1, Storage::disk('public')->allFiles('restaurants'));

        FileUploader::deletePublic($url);

        $this->assertCount(0, Storage::disk('public')->allFiles('restaurants'));
    }

    public function test_replacing_an_image_removes_the_one_it_replaced(): void
    {
        // Otherwise every edit of a product photo leaves its predecessor on disk forever,
        // and «change the picture» is the most repeated action in the whole dashboard.
        $controller = new class
        {
            use HandlesUploads;

            public function call(array $data, Request $request, ?Model $existing): array
            {
                return $this->withPublicUpload($data, $request, 'image', 'products', $existing);
            }
        };

        $first = FileUploader::storePublic(UploadedFile::fake()->image('a.png'), 'products');
        $product = new Product(['image' => $first]);

        $request = Request::create('/', 'POST', [], [], [
            'image' => UploadedFile::fake()->image('b.png'),
        ]);

        $data = $controller->call([], $request, $product);

        $this->assertNotSame($first, $data['image']);
        // One file, and it is the new one.
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
        $this->assertStringContainsString(
            basename(Storage::disk('public')->allFiles('products')[0]),
            $data['image'],
        );
    }

    public function test_the_remove_flag_clears_the_column_and_the_file(): void
    {
        $controller = new class
        {
            use HandlesUploads;

            public function call(array $data, Request $request, ?Model $existing): array
            {
                return $this->withPublicUpload($data, $request, 'image', 'products', $existing);
            }
        };

        $url = FileUploader::storePublic(UploadedFile::fake()->image('a.png'), 'products');
        $product = new Product(['image' => $url]);

        $request = Request::create('/', 'POST', ['remove_image' => '1']);

        $data = $controller->call([], $request, $product);

        $this->assertNull($data['image']);
        // And the flag itself never reaches the model — it is not a column.
        $this->assertArrayNotHasKey('remove_image', $data);
        $this->assertCount(0, Storage::disk('public')->allFiles('products'));
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
