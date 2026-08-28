<?php

namespace Tests\Feature;

use App\Models\CaptainApplication;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_anyone_can_apply_without_an_account(): void
    {
        $response = $this->postJson('/api/v1/captain-applications', [
            'full_name' => 'محمود سعيد',
            'phone' => '01012345678',
            'city' => 'جمصة',
            'vehicle' => 'motorcycle',
        ])->assertCreated();

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
        $this->postJson('/api/v1/captain-applications', [
            'full_name' => 'محمود سعيد',
            'phone' => '0123',
        ])->assertStatus(422);
    }

    public function test_a_phone_with_separators_or_a_country_code_is_normalised(): void
    {
        $this->postJson('/api/v1/captain-applications', [
            'full_name' => 'محمود سعيد',
            'phone' => '+20 101 234 5678',
        ])->assertCreated();

        $this->assertSame('01012345678', CaptainApplication::query()->value('phone'));
    }

    public function test_approving_an_application_creates_the_captain_account(): void
    {
        $this->postJson('/api/v1/captain-applications', [
            'full_name' => 'محمود سعيد',
            'phone' => '01012345678',
            'city' => 'جمصة',
            'vehicle' => 'motorcycle',
        ])->assertCreated();

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

    private function makeAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'phone' => '01000000099',
            'type' => 'admin',
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => \App\Enums\AdminScopeType::Global]);

        return $admin;
    }
}
