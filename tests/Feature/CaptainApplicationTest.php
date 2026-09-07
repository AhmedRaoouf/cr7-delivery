<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\CaptainApplication;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** D-45 — APP-03 submits an application. The account is created by an admin, or not at all. */
class CaptainApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    /**
     * `APP-03` as the app sends it — multipart, both sides of the national ID attached.
     *
     * They are required now (2026-09-05): the front carries the name and the number, the
     * back the address and the expiry, and one photo of a card is half a document. The
     * applicant is holding it while they fill the form in, so this is the one place the
     * pair can be insisted on.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function apply(array $overrides = []): TestResponse
    {
        Storage::fake('local');

        return $this->post('/api/v1/captain-applications', array_merge([
            'full_name' => 'محمود سعيد',
            'phone' => '01012345678',
            'city' => 'جمصة',
            'vehicle' => 'motorcycle',
            'national_id_file' => UploadedFile::fake()->image('id-front.jpg'),
            'national_id_back_file' => UploadedFile::fake()->image('id-back.jpg'),
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_anyone_can_apply_without_an_account(): void
    {
        $response = $this->apply()->assertCreated();

        $reference = $response->json('data.reference');

        $this->assertMatchesRegularExpression('/^CAP-\d{6}-\d{4}$/', $reference);
        $this->assertSame(48, $response->json('data.review_hours'));

        $application = CaptainApplication::query()->firstOrFail();
        $this->assertSame('pending', $application->status->value);
        // The typed city name resolved onto the real row.
        $this->assertSame($this->city->id, $application->city_id);
        // And no account was created.
        $this->assertSame(0, User::query()->where('type', 'driver')->count());
    }

    public function test_a_malformed_phone_is_refused(): void
    {
        $this->apply(['phone' => '0123'])->assertStatus(422);
    }

    public function test_a_phone_with_separators_or_a_country_code_is_normalised(): void
    {
        $this->apply(['phone' => '+20 101 234 5678'])->assertCreated();

        $this->assertSame('01012345678', CaptainApplication::query()->value('phone'));
    }

    public function test_approving_an_application_creates_the_captain_account(): void
    {
        $this->apply()->assertCreated();

        $application = CaptainApplication::query()->firstOrFail();
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin);

        $response = $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/captain-applications/{$application->id}/review", [
                'status' => 'approved',
                'city_id' => $this->city->id,
            ])->assertOk();

        $this->assertNotNull($response->json('data.temp_password'));
        $this->assertSame('approved', $application->fresh()->status->value);

        $driver = Driver::query()->firstOrFail();
        $this->assertSame('01012345678', $driver->user->phone);
        $this->assertTrue($driver->user->must_change_password);
        $this->assertSame($driver->id, $application->fresh()->created_driver_id);
    }

    public function test_an_application_cannot_be_reviewed_twice(): void
    {
        $application = CaptainApplication::query()->create([
            'reference' => 'CAP-260828-0001',
            'full_name' => 'محمود سعيد',
            'phone' => '01012345678',
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'status' => 'rejected',
        ]);

        Sanctum::actingAs($this->makeAdmin());

        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/captain-applications/{$application->id}/review", [
                'status' => 'approved',
                'city_id' => $this->city->id,
            ])->assertStatus(422)
            ->assertJsonPath('error', 'application_already_reviewed');
    }

    // ------------------------------------------------------- the ID card

    public function test_the_two_id_photos_are_required(): void
    {
        // One side of a card is half a document: the front carries the name and the
        // number, the back the address and the expiry.
        $this->apply(['national_id_back_file' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.national_id_back_file.0', 'أرفق صورة ظهر البطاقة');

        $this->apply(['national_id_file' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.national_id_file.0', 'أرفق صورة وجه البطاقة');

        $this->assertSame(0, CaptainApplication::query()->count());
    }

    public function test_an_admin_can_open_both_sides_and_the_paths_never_leave_the_server(): void
    {
        $this->apply()->assertCreated();

        $application = CaptainApplication::query()->firstOrFail();
        Sanctum::actingAs($this->makeAdmin());

        $response = $this->withHeader('X-App', 'admin')
            ->getJson("/api/v1/admin/captain-applications/{$application->id}")
            ->assertOk();

        // A private-disk path in a payload is a file anyone reading the payload can walk
        // to. What travels is a URL the admin fetches back through this API.
        $this->assertNull($response->json('data.national_id_file'));
        $this->assertNull($response->json('data.national_id_back_file'));
        $this->assertSame(
            "admin/captain-applications/{$application->id}/files/national_id",
            $response->json('data.files.national_id'),
        );

        foreach (['national_id', 'national_id_back'] as $kind) {
            $this->withHeader('X-App', 'admin')
                ->get("/api/v1/admin/captain-applications/{$application->id}/files/{$kind}")
                ->assertOk();
        }

        // A side that was never uploaded is absent, not a broken image.
        $this->assertArrayNotHasKey('licence', $response->json('data.files'));

        $this->withHeader('X-App', 'admin')
            ->getJson("/api/v1/admin/captain-applications/{$application->id}/files/passport")
            ->assertNotFound();
    }

    public function test_an_applicant_with_no_scans_still_sends_files_as_an_object(): void
    {
        // PHP has one array type, so a map that filters down to nothing encodes as
        // `[]` and not `{}`. The dashboard parses this field as an object, so a single
        // applicant with no uploads threw inside the server render and returned 500 for
        // the whole of `ADM-30` — every other applicant on the page with it.
        $application = CaptainApplication::query()->create([
            'reference' => CaptainApplication::nextReference(),
            'full_name' => 'مقدّم بلا مرفقات',
            'phone' => '01098765432',
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->makeAdmin());

        $body = $this->withHeader('X-App', 'admin')
            ->getJson("/api/v1/admin/captain-applications/{$application->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"files":{}', $body);
        $this->assertStringNotContainsString('"files":[]', $body);

        $list = $this->withHeader('X-App', 'admin')
            ->getJson('/api/v1/admin/captain-applications')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('"files":[]', $list);
    }

    public function test_approving_carries_the_id_photos_onto_the_captains_account(): void
    {
        $this->apply()->assertCreated();

        $application = CaptainApplication::query()->firstOrFail();
        Sanctum::actingAs($this->makeAdmin());

        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/captain-applications/{$application->id}/review", [
                'status' => 'approved',
                'city_id' => $this->city->id,
            ])->assertOk();

        $driver = Driver::query()->findOrFail($application->fresh()->created_driver_id);
        $types = $driver->documents()->pluck('type')->map(fn ($type) => $type->value ?? $type)->all();

        // Without this the approved captain's documents card was empty and the only copy
        // of their ID stayed on a row nobody opens again.
        $this->assertEqualsCanonicalizing(['national_id', 'national_id_back'], $types);
        $this->assertSame(
            ['approved', 'approved'],
            $driver->documents()->pluck('status')->map(fn ($status) => $status->value ?? $status)->all(),
        );
    }

    private function makeAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'phone' => '01000000099',
            'type' => 'admin',
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
