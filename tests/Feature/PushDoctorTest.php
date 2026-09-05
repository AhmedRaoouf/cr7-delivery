<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The diagnostic crashed on the deployment the first time it had something to say.
 *
 * «A cell must be a TableCell, a scalar or an object implementing "__toString()",
 * "App\Enums\DeviceApp" given» — the third time an enum-cast column has bitten on
 * this exact path. `DeviceController::store()` answered 500 for it, the
 * `ready_source === 'timer'` comparison was silently false for it, and now the
 * command written to explain why push was broken was itself broken by it, in the
 * one branch that only runs once a phone has actually registered — which is to
 * say the branch nobody could reach until the bug in front of it was fixed.
 */
class PushDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_registered_devices_without_choking_on_the_enum_columns(): void
    {
        $user = User::factory()->create(['type' => 'customer']);

        DeviceToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'phone-1',
            'token' => 'token-1',
            'platform' => 'android',
            'app' => 'customer',
            'last_used_at' => now(),
        ]);

        $this->artisan('cr7:push-doctor')
            ->expectsOutputToContain('registered devices')
            ->assertSuccessful();
    }

    public function test_it_says_so_when_no_phone_has_registered(): void
    {
        $this->artisan('cr7:push-doctor')
            ->expectsOutputToContain('No phone on the platform can receive anything.')
            ->assertSuccessful();
    }

    public function test_a_test_send_to_an_account_with_no_device_fails_loudly(): void
    {
        $user = User::factory()->create(['type' => 'customer']);

        $this->artisan("cr7:push-doctor --to={$user->id}")
            ->assertFailed();
    }
}
