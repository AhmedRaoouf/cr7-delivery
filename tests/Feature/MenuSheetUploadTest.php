<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\User;
use App\Support\PdfPages;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «عايز صفحات المنيو في الأماكن تبقى صور أو PDF ويتعرض كصور للصفحات» —
 * operator, 2026-09-12.
 *
 * Most of that already worked. `restaurants.menu_sheets` has had a column, an
 * `array` cast, a customer resource field and seed data since 2026-08, and the
 * app has drawn them since `CUS-15c`: `PrintedMenuSection` for the rail,
 * `ImageGallerySheet` for a page at full width with pinch-zoom to ×‪4‬ and swipe
 * between pages.
 *
 * What did not exist was **any way to put a sheet in**. `menu_sheets` appeared
 * in zero form requests and zero controllers, so the only printed menus on the
 * platform were the ones in `database/data/catalog.json`. An operator who
 * photographed a menu had nowhere to send it.
 *
 * A PDF is rasterised on upload rather than rendered in the app. That keeps the
 * pages identical to every other image on the platform — same viewer, same
 * zoom, same cache — and costs neither client a PDF dependency. Verified on the
 * deployment before it was written: `imagick` loaded, Ghostscript at
 * `/usr/bin/gs`, `PDF` in `Imagick::queryFormats()`.
 */
class MenuSheetUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');
    }

    /** `X-App: admin` — doc 12 §1.1 gates every admin route on it, header and all. */
    private function asAdmin(): TestResponse|\Illuminate\Foundation\Testing\TestCase
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    public function test_an_admin_can_upload_the_printed_sheets_as_images(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');

        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", [
                '_method' => 'PATCH',
                'menu_sheets' => [
                    UploadedFile::fake()->image('front.jpg', 1200, 1700),
                    UploadedFile::fake()->image('back.jpg', 1200, 1700),
                ],
            ])
            ->assertOk();

        $sheets = $restaurant->fresh()->menu_sheets;

        $this->assertCount(2, $sheets);
    }

    public function test_a_pdf_arrives_as_one_file_and_is_stored_as_one_image_per_page(): void
    {
        if (! PdfPages::isSupported()) {
            $this->markTestSkipped('imagick with PDF support is not available here.');
        }

        $restaurant = $this->makeRestaurant('سعد الدمياطي');

        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", [
                '_method' => 'PATCH',
                'menu_sheets' => [$this->threePagePdf()],
            ])
            ->assertOk();

        $sheets = $restaurant->fresh()->menu_sheets;

        // One upload, three pages — which is the whole point. The app is handed
        // images and never learns that a PDF was involved.
        $this->assertCount(3, $sheets);

        foreach ($sheets as $url) {
            $this->assertStringEndsWith('.jpg', $url);
        }
    }

    public function test_the_sheets_reach_the_customer_screen(): void
    {
        $restaurant = $this->makeRestaurant('كنج بروست');

        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", [
                '_method' => 'PATCH',
                'menu_sheets' => [UploadedFile::fake()->image('menu.jpg', 1000, 1400)],
            ])
            ->assertOk();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.menu_sheets');
    }

    public function test_removing_the_sheets_empties_them_rather_than_leaving_the_old_ones(): void
    {
        $restaurant = $this->makeRestaurant('ناصر القياس');
        $restaurant->update(['menu_sheets' => ['catalog/menus/old.png']]);

        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", [
                '_method' => 'PATCH',
                'remove_menu_sheets' => true,
            ])
            ->assertOk();

        $this->assertSame([], $restaurant->fresh()->menu_sheets);
    }

    public function test_a_second_upload_adds_pages_rather_than_replacing_the_first(): void
    {
        $restaurant = $this->makeRestaurant('صواني الدمياطي');
        $restaurant->update(['menu_sheets' => ['catalog/menus/page-1.jpg']]);

        // A menu is photographed a sheet at a time. The first version of this
        // helper wrote the upload over the column, so the operator's second
        // visit silently deleted their first visit's pages.
        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", [
                '_method' => 'PATCH',
                'menu_sheets' => [UploadedFile::fake()->image('page-2.jpg', 1200, 1700)],
            ])
            ->assertOk();

        $sheets = $restaurant->fresh()->menu_sheets;

        $this->assertCount(2, $sheets);
        $this->assertSame('catalog/menus/page-1.jpg', $sheets[0]);
    }

    public function test_a_page_the_form_stopped_showing_is_dropped_and_deleted(): void
    {
        $restaurant = $this->makeRestaurant('بوكس ع السريع');

        Storage::disk('public')->put('catalog/menus/keep.jpg', 'x');
        Storage::disk('public')->put('catalog/menus/drop.jpg', 'x');

        // The column holds what `FileUploader::storePublic()` returned, which is
        // a **URL** — and `deletePublic()` refuses anything that is not one, so
        // a fixture written as a bare path proves nothing about the delete.
        $keep = Storage::disk('public')->url('catalog/menus/keep.jpg');
        $drop = Storage::disk('public')->url('catalog/menus/drop.jpg');
        $restaurant->update(['menu_sheets' => [$keep, $drop]]);

        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", [
                '_method' => 'PATCH',
                'keep_menu_sheets' => [$keep],
            ])
            ->assertOk();

        $this->assertSame([$keep], $restaurant->fresh()->menu_sheets);
        Storage::disk('public')->assertExists('catalog/menus/keep.jpg');
        Storage::disk('public')->assertMissing('catalog/menus/drop.jpg');
    }

    public function test_an_edit_that_never_mentions_the_sheets_leaves_them_alone(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $restaurant->update(['menu_sheets' => ['catalog/menus/page-1.jpg']]);

        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", ['name_ar' => 'سندوتش عمر'])
            ->assertOk();

        $this->assertSame(['catalog/menus/page-1.jpg'], $restaurant->fresh()->menu_sheets);
    }

    public function test_a_file_that_is_neither_an_image_nor_a_pdf_is_refused_by_name(): void
    {
        $restaurant = $this->makeRestaurant('الركن العربي');

        $this->asAdmin()
            ->patchJson("/api/v1/admin/restaurants/{$restaurant->id}", [
                '_method' => 'PATCH',
                'menu_sheets' => [UploadedFile::fake()->create('menu.exe', 40)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['menu_sheets.0']);
    }

    /** A genuine three-page PDF — `UploadedFile::fake()` cannot make one. */
    private function threePagePdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'menu-').'.pdf';

        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R 4 0 R 5 0 R]/Count 3>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj',
            '4 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj',
            '5 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        // A real cross-reference table. Ghostscript will repair a PDF without
        // one and still render it, but it prints «The file has been damaged» to
        // stderr while doing so, and a test suite that shouts is a test suite
        // people stop reading.
        $start = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$start}\n%%EOF";

        file_put_contents($path, $pdf);

        return new UploadedFile($path, 'menu.pdf', 'application/pdf', test: true);
    }
}
