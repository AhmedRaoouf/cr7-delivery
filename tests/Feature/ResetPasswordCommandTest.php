<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The way back into an account whose first password was never shown to anyone. */
class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_to_the_configured_first_password_and_the_account_opens(): void
    {
        config(['auth.temp_password' => '123456789']);

        User::factory()->create([
            'phone' => '01090009599',
            'type' => 'driver',
            'password' => Hash::make('nobody-knows-this'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        // Typed the way an operator reads it off a phone, not the way it is stored.
        $this->artisan('cr7:reset-password', ['login' => '+20 109 000 9599'])
            ->expectsOutputToContain('123456789')
            ->assertSuccessful();

        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/auth/login', ['login' => '01090009599', 'password' => '123456789'])
            ->assertOk()
            // A password that has been printed on a terminal must not stay the password.
            ->assertJsonPath('data.user.must_change_password', true);
    }

    public function test_an_unknown_login_fails_rather_than_touching_a_row(): void
    {
        $this->artisan('cr7:reset-password', ['login' => '01111111111'])->assertFailed();
    }
}
