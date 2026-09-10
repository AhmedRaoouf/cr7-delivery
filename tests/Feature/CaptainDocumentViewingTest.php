<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `ADM-18`'s documents card — «امكانيه تنزل المستندات المرفوعه ورؤيتها كصور في الداشبورد».
 *
 * The bytes were always reachable: `GET /admin/driver-documents/{id}/file` has existed
 * since the account moderation work. What the screen had was the **raw model**, whose
 * only path is `driver-documents/xyz.jpg` on the private disk — an address that means
 * nothing to a browser. So the card printed the word «national_id» beside a status chip
 * and the photograph behind it was, in practice, unreachable.
 *
 * Two things are asserted, and doc 12 §5 is the reason for the third:
 *
 * 1. The row carries a **URL**, not a disk path.
 * 2. The same route saves the file when asked, with a name that identifies the captain —
 *    `national_id.jpg` in a folder of thirty downloads is not a document.
 * 3. The disk path itself is **not** emitted, and the route stays behind
 *    `drivers.review_documents`. An identity document never gets a public URL.
 */
class CaptainDocumentViewingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        $this->withHeader('X-App', 'admin');
        Storage::fake('local');
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeCaptain(): Driver
    {
        $user = User::factory()->create(['type' => 'driver']);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_available' => true,
        ]);
    }

    public function test_the_card_is_handed_a_url_it_can_draw_not_a_disk_path(): void
    {
        $this->actingAsSuperAdmin();
        $captain = $this->makeCaptain();

        $this->postJson("/api/v1/admin/drivers/{$captain->id}/documents", [
            'type' => 'national_id',
            'file' => UploadedFile::fake()->image('front.jpg'),
        ])->assertCreated();

        $row = $this->getJson("/api/v1/admin/drivers/{$captain->id}/documents")->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('file', $row);
        $this->assertSame("admin/driver-documents/{$row['id']}/file", $row['file_url']);
        $this->assertSame("admin/driver-documents/{$row['id']}/file?download=1", $row['download_url']);
        $this->assertTrue($row['is_image']);
    }

    /** A PDF scan is a real document, and the card has to offer the download instead. */
    public function test_a_scan_that_is_not_a_picture_says_so(): void
    {
        $this->actingAsSuperAdmin();
        $captain = $this->makeCaptain();

        $this->postJson("/api/v1/admin/drivers/{$captain->id}/documents", [
            'type' => 'criminal_record',
            'file' => UploadedFile::fake()->create('record.pdf', 40, 'application/pdf'),
        ])->assertCreated();

        $row = $this->getJson("/api/v1/admin/drivers/{$captain->id}/documents")->assertOk()->json('data.0');

        $this->assertFalse($row['is_image']);
    }

    public function test_the_file_is_drawn_inline_and_saved_on_request_under_a_name_that_says_whose_it_is(): void
    {
        $this->actingAsSuperAdmin();
        $captain = $this->makeCaptain();

        $id = $this->postJson("/api/v1/admin/drivers/{$captain->id}/documents", [
            'type' => 'national_id',
            'file' => UploadedFile::fake()->image('front.jpg'),
        ])->assertCreated()->json('data.id');

        // Inline is what an `<img src>` needs: a browser handed `attachment` on an image
        // tag renders nothing and logs nothing.
        $inline = $this->get("/api/v1/admin/driver-documents/{$id}/file")->assertOk();
        $this->assertStringNotContainsString('attachment', (string) $inline->headers->get('content-disposition'));

        $saved = $this->get("/api/v1/admin/driver-documents/{$id}/file?download=1")->assertOk();
        $disposition = (string) $saved->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString("captain-{$captain->id}-national_id.jpg", $disposition);
    }

    public function test_an_admin_without_the_grant_cannot_read_a_captains_identity_document(): void
    {
        $this->actingAsSuperAdmin();
        $captain = $this->makeCaptain();

        $id = $this->postJson("/api/v1/admin/drivers/{$captain->id}/documents", [
            'type' => 'national_id',
            'file' => UploadedFile::fake()->image('front.jpg'),
        ])->assertCreated()->json('data.id');

        $stranger = User::factory()->create(['type' => 'admin']);
        $stranger->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($stranger);

        $this->get("/api/v1/admin/driver-documents/{$id}/file")->assertForbidden();
        $this->get("/api/v1/admin/driver-documents/{$id}/file?download=1")->assertForbidden();
    }
}
