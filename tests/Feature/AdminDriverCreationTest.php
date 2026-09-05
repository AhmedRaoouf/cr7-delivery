<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\CaptainApplication;
use App\Models\Driver;
use App\Models\ServiceZone;
use App\Models\User;
use Database\Seeders\OperatorSettingsSeeder;
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
 * `ADM-16` — creating and editing a captain from the admin dashboard.
 *
 * Four things met on this one screen and none of them announced itself:
 *
 *  1. Only `StoreCaptainApplicationRequest` normalised a phone, so the same number an
 *     applicant could submit was refused when an admin typed it with spaces or a `+20`.
 *  2. `earning_type` and `earning_value` are required and the form had no fields for
 *     them — three validation errors, of which the screen could only place one.
 *  3. `name`, `phone` and `email` are `users` columns and `UpdateDriverRequest` did not
 *     list them, so an edit reported success and changed nothing.
 *  4. A phone that already had an account could still file an application, and the
 *     collision surfaced as a 500 inside the approval transaction rather than as a
 *     sentence on the applicant's screen.
 */
class AdminDriverCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        // The role says what; the scope says whose rows (D-50). Without the scope row
        // every driver is out of scope and each edit answers 404, never 403.
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'محمود سعيد',
            'phone' => '01090009599',
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
        ], $overrides);
    }

    public function test_a_captain_can_be_created_with_nothing_but_a_name_a_phone_and_a_city(): void
    {
        $this->admin();
        $this->seed(SettingsSeeder::class);
        $this->seed(OperatorSettingsSeeder::class);

        // The vehicle, the plate and the earning rule left the Admin form: the first two
        // belong to the captain's own application and the third is a platform setting.
        // What must not happen is the form losing them and the request being rejected.
        $response = $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', [
                'name' => 'وائل محمد',
                'phone' => '01012345678',
                'city_id' => $this->city->id,
            ])
            ->assertCreated();

        $driver = Driver::query()->findOrFail($response->json('data.driver.id'));

        $this->assertSame('motorcycle', $driver->vehicle_type->value ?? $driver->vehicle_type);
        $this->assertSame('percent_of_delivery_fee', $driver->earning_type->value ?? $driver->earning_type);
        // 0 is «the standard split», read from `delivery.platform_commission_rate` — not
        // «this captain earns nothing».
        $this->assertSame(0.0, (float) $driver->earning_value);
    }

    public function test_the_areas_a_captain_covers_are_saved_with_the_account(): void
    {
        $this->admin();

        $zones = ServiceZone::query()->where('city_id', $this->city->id)->limit(2)->pluck('id');
        $this->assertNotEmpty($zones, 'the location seed must provide at least one zone');

        $response = $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', [
                'name' => 'وائل محمد',
                'phone' => '01012345679',
                'city_id' => $this->city->id,
                'zone_ids' => $zones->all(),
            ])
            ->assertCreated();

        $driver = Driver::query()->findOrFail($response->json('data.driver.id'));

        $this->assertEqualsCanonicalizing($zones->all(), $driver->assignedZones()->pluck('service_zones.id')->all());
    }

    public function test_a_phone_written_the_way_a_person_writes_it_is_accepted(): void
    {
        $this->admin();

        // Every one of these is the same number. Before normalisation moved into a shared
        // trait, all four were «رقم الهاتف غير صحيح».
        foreach (['+20 109 000 9599', '0020109 000 9599', '1090009599', '٠١٠٩٠٠٠٩٥٩٩'] as $index => $typed) {
            $response = $this->withHeaders(['X-App' => 'admin'])
                ->postJson('/api/v1/admin/drivers', $this->payload([
                    'phone' => $typed,
                    // A distinct national id keeps the rows apart; the phone is the assertion.
                    'national_id' => (string) (30000000000000 + $index),
                ]));

            if ($index === 0) {
                $response->assertCreated();
                $this->assertSame('01090009599', User::query()->where('type', 'driver')->value('phone'));

                continue;
            }

            // The rest normalise onto a number that now exists — which is the uniqueness
            // rule proving the normalisation happened, not a format complaint.
            $response->assertStatus(422)->assertJsonPath('errors.phone.0', 'هذا الرقم مسجل بالفعل');
        }
    }

    public function test_a_genuinely_malformed_phone_is_still_refused(): void
    {
        $this->admin();

        $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', $this->payload(['phone' => '0999']))
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'رقم الهاتف غير صحيح — 11 رقمًا تبدأ بـ 010 أو 011 أو 012 أو 015');
    }

    /**
     * Reversed on purpose, 2026-09-05.
     *
     * This used to assert that omitting the earning rule was a 422 — written when the
     * form had no fields for it and three unplaced errors read as a phone problem. The
     * fields have since left the form deliberately: the rule is a platform setting, and
     * a required-per-captain percentage is how a fleet ends up on four different splits.
     * A value that *is* sent still has to be one of the three types.
     */
    public function test_an_omitted_earning_rule_falls_back_and_a_wrong_one_is_still_refused(): void
    {
        $this->admin();

        $payload = $this->payload();
        unset($payload['earning_type'], $payload['earning_value']);

        $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', $payload)
            ->assertCreated();

        $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', $this->payload([
                'phone' => '01090009598',
                'earning_type' => 'whatever',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['earning_type']);
    }

    public function test_the_created_account_signs_in_with_the_configured_first_password(): void
    {
        config(['auth.temp_password' => '123456789']);
        $this->admin();

        $created = $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', $this->payload())
            ->assertCreated();

        $this->assertSame('123456789', $created->json('data.temp_password'));

        // The password is only worth anything if it actually opens the account.
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/auth/login', ['login' => '01090009599', 'password' => '123456789'])
            ->assertOk()
            ->assertJsonPath('data.user.type', 'driver')
            ->assertJsonPath('data.user.must_change_password', true);
    }

    public function test_an_empty_configured_password_goes_back_to_a_random_one(): void
    {
        config(['auth.temp_password' => '']);
        $this->admin();

        $temp = $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', $this->payload())
            ->assertCreated()
            ->json('data.temp_password');

        $this->assertNotSame('123456789', $temp);
        $this->assertSame(12, strlen((string) $temp));
    }

    public function test_editing_a_captain_writes_the_name_and_phone_that_live_on_users(): void
    {
        $this->admin();

        $driverId = $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', $this->payload())
            ->assertCreated()
            ->json('data.driver.id');

        $this->withHeaders(['X-App' => 'admin'])
            ->patchJson("/api/v1/admin/drivers/{$driverId}", [
                'name' => 'محمود سعيد المصري',
                'phone' => '+20 111 222 3344',
                'vehicle_plate' => 'ج ط ب 1234',
            ])
            ->assertOk();

        $user = Driver::query()->findOrFail($driverId)->user;

        $this->assertSame('محمود سعيد المصري', $user->name);
        $this->assertSame('01112223344', $user->phone);
        $this->assertSame('ج ط ب 1234', Driver::query()->findOrFail($driverId)->vehicle_plate);
    }

    public function test_a_captain_keeps_their_own_phone_on_an_unrelated_edit(): void
    {
        $this->admin();

        $driverId = $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/admin/drivers', $this->payload())
            ->assertCreated()
            ->json('data.driver.id');

        // `unique:users,phone` has to ignore the row it belongs to, or a captain can
        // never be edited again once created.
        $this->withHeaders(['X-App' => 'admin'])
            ->patchJson("/api/v1/admin/drivers/{$driverId}", [
                'phone' => '01090009599',
                'vehicle_type' => 'car',
            ])
            ->assertOk();
    }

    /**
     * `APP-03` as the app sends it — multipart, with both sides of the national ID.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function apply(array $overrides = []): TestResponse
    {
        Storage::fake('local');

        return $this->post('/api/v1/captain-applications', array_merge([
            'full_name' => 'محمود سعيد',
            'phone' => '01090009599',
            'city' => 'جمصة',
            'vehicle' => 'motorcycle',
            'national_id_file' => UploadedFile::fake()->image('id-front.jpg'),
            'national_id_back_file' => UploadedFile::fake()->image('id-back.jpg'),
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_a_registered_phone_cannot_file_an_application(): void
    {
        User::factory()->create(['phone' => '01090009599', 'type' => 'customer']);

        $this->apply(['phone' => '+20 109 000 9599'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'هذا الرقم مسجل بالفعل. سجّل الدخول بدلًا من تقديم طلب جديد.');

        $this->assertSame(0, CaptainApplication::query()->count());
    }

    public function test_the_same_number_cannot_queue_twice(): void
    {
        $this->apply()->assertCreated();

        // A different sentence from the registered-phone one: there is no account to sign
        // in to yet, and telling an applicant to sign in to nothing is worse than silence.
        $this->apply()
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'لديك طلب قيد المراجعة بهذا الرقم. انتظر الرد قبل تقديم طلب جديد.');

        $this->assertSame(1, CaptainApplication::query()->count());
    }

    public function test_a_rejected_applicant_may_apply_again(): void
    {
        $this->apply()->assertCreated();
        CaptainApplication::query()->firstOrFail()->update(['status' => 'rejected']);

        // Only a live queue blocks. A refusal is not a life sentence.
        $this->apply()->assertCreated();
        $this->assertSame(2, CaptainApplication::query()->count());
    }
}
