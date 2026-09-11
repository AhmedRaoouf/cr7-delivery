<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * D-70 — the phone is four shells.
 *
 * The mobile binary sends one `X-App: app` for every one of them, so the header
 * stopped being able to say which kind of account is calling and `users.type`
 * is left holding the whole answer — which is what doc 12 §1.1 always said it
 * was. These tests pin the two halves of that: `app` lets all four in, and no
 * other origin got wider while it happened.
 */
class MobileFourShellsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    private function account(string $type): User
    {
        return User::factory()->create([
            'phone' => '01020508483',
            'type' => $type,
            'password' => Hash::make('Cr7Delivery#2026'),
            'is_active' => true,
        ]);
    }

    /** @return list<array{string}> */
    public static function types(): array
    {
        return [['customer'], ['driver'], ['restaurant'], ['admin']];
    }

    #[DataProvider('types')]
    public function test_every_account_type_signs_in_from_the_phone(string $type): void
    {
        $this->account($type);

        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/auth/login', ['login' => '01020508483', 'password' => 'Cr7Delivery#2026'])
            ->assertOk()
            ->assertJsonPath('data.user.type', $type);
    }

    public function test_a_places_token_reaches_its_own_board_from_the_phone(): void
    {
        $owner = $this->makeRestaurant('سندوتش عمر')->owner;

        $this->actingAs($owner)
            ->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/restaurant/me')
            ->assertOk();
    }

    public function test_an_admins_token_reaches_the_console_from_the_phone(): void
    {
        $admin = $this->account('admin');
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        $this->actingAs($admin)
            ->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/admin/dashboard/summary')
            ->assertOk();
    }

    public function test_a_place_still_cannot_use_the_admin_origin(): void
    {
        $this->account('restaurant');

        // D-78 — refused, and refused in the words of a wrong password. The old 403
        // named the account's own type back at an anonymous caller.
        $this->withHeaders(['X-App' => 'admin'])
            ->postJson('/api/v1/auth/login', ['login' => '01020508483', 'password' => 'Cr7Delivery#2026'])
            ->assertStatus(422)
            // The message is whatever `__()` renders for the caller's locale — the point
            // is that it is the *same* refusal as a wrong password, code and all.
            ->assertJsonPath('error', 'invalid_credentials');
    }

    public function test_a_captains_token_is_still_refused_on_the_admin_origin(): void
    {
        $captain = $this->account('driver');

        $this->actingAs($captain)
            ->withHeaders(['X-App' => 'admin'])
            ->getJson('/api/v1/admin/dashboard/summary')
            ->assertStatus(403);
    }

    /** The phone is four shells; it is still not a fifth authorization. */
    public function test_a_place_on_the_phone_still_cannot_read_the_console(): void
    {
        $owner = $this->makeRestaurant('ابن البلد')->owner;

        $this->actingAs($owner)
            ->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/admin/dashboard/summary')
            ->assertStatus(403);
    }

    /** @return list<array{string}> */
    public static function deviceApps(): array
    {
        return [['restaurant'], ['admin']];
    }

    #[DataProvider('deviceApps')]
    public function test_the_phone_registers_a_device_for_the_new_shells(string $app): void
    {
        $user = $this->account($app);

        $this->actingAs($user)
            ->withHeaders(['X-App' => 'app', 'X-Device-Id' => 'test-device'])
            ->postJson('/api/v1/devices', [
                'token' => 'fcm-token',
                'platform' => 'android',
                'app' => $app,
                'device_id' => 'test-device',
            ])
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'app' => $app,
        ]);
    }
}
