<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\FileUploader;
use App\Support\ImageResizer;
use App\Support\Media\ThumbUrl;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 72 — `thumb_url` on the picker, the library and a product's gallery.
 *
 * One mechanism: `/img/{width}/…` (`ImageResizer`), extended from the catalogue to the uploads
 * disk and the shipped folders. What is worth a test: that a thumbnail is a fetchable, smaller
 * picture; that whatever cannot be resized is answered as itself; and that the wider reach did
 * not turn the route into a file reader.
 */
class ThumbnailTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> cached thumbnails this test made, removed again */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            ImageResizer::forget($path);
        }

        @unlink(public_path('shipped/__thumb-test.png'));
        @rmdir(public_path('shipped'));

        parent::tearDown();
    }

    private function bigUpload(string $name = 'big.jpg', int $w = 1000, int $h = 700): string
    {
        $file = UploadedFile::fake()->image($name, $w, $h);
        Storage::disk('public')->putFileAs('products', $file, $name);
        $this->made[] = 'storage/products/'.$name;

        return Storage::disk('public')->url('products/'.$name);
    }

    private function admin(): static
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    /** The path (and query) of an absolute URL on this app. */
    private function local(string $url): string
    {
        return (string) preg_replace('#^https?://[^/]+#', '', $url);
    }

    public function test_an_upload_gets_a_thumbnail_url_that_serves_a_384_wide_picture(): void
    {
        $url = $this->bigUpload();

        $thumb = ThumbUrl::for($url);

        $this->assertNotSame($url, $thumb);
        $this->assertMatchesRegularExpression('#/img/384/storage/products/big\.jpg\?v=\d+$#', $thumb);

        $response = $this->get($this->local($thumb))->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();

        $this->assertSame(384, getimagesize($file)[0]);
        $this->assertSame(269, getimagesize($file)[1], 'the aspect ratio is kept');
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // The second request is a file on disk, which is the whole point.
        $this->assertFileExists(public_path('img/384/storage/products/big.jpg'));
    }

    public function test_a_picture_already_smaller_than_the_thumbnail_is_answered_as_itself(): void
    {
        $url = $this->bigUpload('small.png', 200, 100);

        $this->get($this->local(ThumbUrl::for($url)))->assertOk();
        $this->assertFileDoesNotExist(public_path('img/384/storage/products/small.png'));
    }

    public function test_whatever_cannot_be_resized_is_answered_as_the_original_url(): void
    {
        $gif = Storage::disk('public')->url('products/a.gif');
        Storage::disk('public')->put('products/a.gif', 'GIF89a');
        Storage::disk('public')->put('products/with space.jpg', 'x');

        foreach ([
            'a remote host' => 'https://cdn.example.com/dish.jpg',
            'a gif' => $gif,
            'a missing file' => Storage::disk('public')->url('products/gone.jpg'),
            'a name the route would refuse' => Storage::disk('public')->url('products/with space.jpg'),
            'no extension' => 'https://cdn.example.com/dish',
            'a path under another root' => asset('vendor/some.png'),
        ] as $why => $url) {
            $this->assertSame($url, ThumbUrl::for($url), $why);
        }

        $this->assertNull(ThumbUrl::for(null));
        $this->assertSame('', ThumbUrl::for(''));
    }

    public function test_a_file_under_a_shipped_folder_is_resized_too(): void
    {
        @mkdir(public_path('shipped'), 0755, true);
        $image = imagecreatetruecolor(900, 600);
        imagepng($image, public_path('shipped/__thumb-test.png'));
        imagedestroy($image);
        $this->made[] = 'shipped/__thumb-test.png';

        $thumb = ThumbUrl::for(asset('shipped/__thumb-test.png'));

        $this->assertStringContainsString('/img/384/shipped/__thumb-test.png?v=', $thumb);
        $file = $this->get($this->local($thumb))->assertOk()->baseResponse->getFile()->getPathname();
        $this->assertSame(384, getimagesize($file)[0]);
    }

    public function test_the_system_placeholder_gets_a_thumbnail(): void
    {
        $thumb = ThumbUrl::for(asset('media/system/default-placeholder.webp'));
        $this->made[] = 'media/system/default-placeholder.webp';

        $this->assertStringContainsString('/img/384/media/system/default-placeholder.webp?v=', $thumb);
        $file = $this->get($this->local($thumb))->assertOk()->baseResponse->getFile()->getPathname();
        $this->assertSame(384, getimagesize($file)[0]);
    }

    public function test_the_resizer_cannot_be_turned_into_a_file_reader(): void
    {
        $this->bigUpload();
        Storage::disk('public')->put('secret.txt', 'nope');
        file_put_contents(base_path('.thumb-probe.png'), 'not an image');

        try {
            foreach ([
                'storage/../.env',
                'storage/products/../../.env',
                'storage/../../.thumb-probe.png',
                'shipped/../.env',
                'media/system/../../.env',
                'catalog/../../.env',
                'media/other/x.png',
                'media/x.png',
                'app/Models/User.php',
                'storage',
                'storage/',
                '/etc/passwd',
                'storage/products\\big.jpg',
                "storage/products/big.jpg\0.png",
                'storage/secret.txt',
            ] as $path) {
                $this->assertNull(ImageResizer::make($path, 384), $path);
                $this->assertNull(ImageResizer::source($path), $path);
            }

            // Over HTTP: the router's pattern refuses anything odd before PHP runs.
            foreach (['storage/../.env', 'storage/%2e%2e/.env', 'storage/..%2f.env', 'app/Models/User.php', 'media/x.png', 'storage/products/big.jpg%00.png'] as $path) {
                $this->assertContains($this->get("/img/384/{$path}")->getStatusCode(), [404, 400], $path);
            }
        } finally {
            @unlink(base_path('.thumb-probe.png'));
        }
    }

    public function test_a_symlink_inside_the_uploads_disk_cannot_lead_out_of_it(): void
    {
        $root = Storage::disk('public')->path('');
        $outside = sys_get_temp_dir().'/cr7-thumb-outside-'.uniqid();
        mkdir($outside);
        $image = imagecreatetruecolor(900, 600);
        imagepng($image, $outside.'/leak.png');
        imagedestroy($image);

        try {
            symlink($outside, rtrim($root, '/').'/escape');

            $this->assertNull(ImageResizer::source('storage/escape/leak.png'));
            $this->get('/img/384/storage/escape/leak.png')->assertNotFound();
            $this->assertSame(
                Storage::disk('public')->url('escape/leak.png'),
                ThumbUrl::for(Storage::disk('public')->url('escape/leak.png')),
            );
        } finally {
            @unlink(rtrim($root, '/').'/escape');
            @unlink($outside.'/leak.png');
            @rmdir($outside);
        }
    }

    public function test_only_the_allowed_widths_are_served(): void
    {
        $this->bigUpload();

        foreach ([500, 383, 385, 0, 3000] as $width) {
            $this->get("/img/{$width}/storage/products/big.jpg")->assertNotFound();
        }

        foreach (ImageResizer::WIDTHS as $width) {
            if ($width < 1000) {
                $this->get("/img/{$width}/storage/products/big.jpg")->assertOk();
            }
        }

        $this->made[] = 'storage/products/big.jpg';
    }

    public function test_deleting_the_file_removes_its_cached_thumbnails(): void
    {
        $url = $this->bigUpload();
        $this->get($this->local(ThumbUrl::for($url)))->assertOk();
        $this->assertFileExists(public_path('img/384/storage/products/big.jpg'));

        FileUploader::deletePublic($url, holders: 0);

        $this->assertFileDoesNotExist(public_path('img/384/storage/products/big.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('products/big.jpg'));
        $this->assertSame($url, ThumbUrl::for($url), 'and the thumbnail URL falls back to the original');
    }

    public function test_the_picker_the_library_and_the_gallery_carry_thumb_url(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $url = $this->bigUpload();
        $product = $this->makeProduct($place, 'برجر', 50);
        $product->update(['gallery' => [$url, 'https://cdn.example.com/remote.jpg']]);

        $path = 'media-library/lib.jpg';
        Storage::disk('public')->putFileAs('media-library', UploadedFile::fake()->image('lib.jpg', 900, 900), 'lib.jpg');
        $this->made[] = 'storage/'.$path;
        $asset = MediaAsset::create([
            'name_ar' => 'مكتبة', 'name_en' => 'lib', 'filename' => 'lib.jpg', 'disk' => 'public', 'path' => $path,
            'mime' => 'image/jpeg', 'bytes' => 5, 'sha1' => sha1('lib'), 'source' => MediaAsset::SOURCE_UPLOADED,
            'usage_types' => ['product'], 'visibility' => 'global',
        ]);

        // The place's picker: an own picture and a library asset.
        Sanctum::actingAs($place->owner);
        $items = collect($this->withHeaders(['X-App' => 'restaurant'])->getJson('/api/v1/restaurant/media/picker?field=product_image')->assertOk()->json('data'));
        $own = $items->firstWhere('url', $url);
        $library = $items->firstWhere('ref', 'asset:'.$asset->id);
        $remote = $items->firstWhere('url', 'https://cdn.example.com/remote.jpg');

        $this->assertStringContainsString('/img/384/storage/products/big.jpg?v=', $own['thumb_url']);
        $this->assertStringContainsString('/img/384/storage/media-library/lib.jpg?v=', $library['thumb_url']);
        $this->assertSame('https://cdn.example.com/remote.jpg', $remote['thumb_url']);

        // The admin's picker, list and show.
        $this->admin()->getJson("/api/v1/admin/media/picker?field=product_image&restaurant_id={$place->id}")->assertOk()
            ->assertJsonPath('data.0.thumb_url', fn ($v) => is_string($v) && $v !== '');
        $list = collect($this->admin()->getJson('/api/v1/admin/media?per_page=100')->assertOk()->json('data'));
        $this->assertStringContainsString('/img/384/storage/media-library/lib.jpg', $list->firstWhere('id', $asset->id)['thumb_url']);
        $this->admin()->getJson("/api/v1/admin/media/{$asset->id}")->assertOk()
            ->assertJsonPath('data.thumb_url', ThumbUrl::for($asset->url));

        // Every asset in the library has a thumb_url, the protected placeholder included.
        foreach ($list as $row) {
            $this->assertNotEmpty($row['thumb_url'], (string) $row['id']);
        }

        // A product's gallery items.
        Sanctum::actingAs($place->owner);
        $items = $this->withHeaders(['X-App' => 'restaurant'])->getJson("/api/v1/restaurant/products/{$product->id}")->assertOk()->json('data.gallery_items');
        $this->assertStringContainsString('/img/384/storage/products/big.jpg?v=', $items[0]['thumb_url']);
        $this->assertSame('https://cdn.example.com/remote.jpg', $items[1]['thumb_url']);

        // …and the thumbnails are really fetchable.
        $this->get($this->local($items[0]['thumb_url']))->assertOk();
        $this->get($this->local($library['thumb_url']))->assertOk();
    }

    public function test_the_available_app_images_carry_a_thumb_url(): void
    {
        $rows = $this->admin()->getJson('/api/v1/admin/media/available?per_page=5')->assertOk()->json('data');

        foreach ($rows as $row) {
            $this->assertNotEmpty($row['thumb_url']);
        }
    }
}
