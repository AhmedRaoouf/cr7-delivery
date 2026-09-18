<?php

namespace Tests\Feature\Security;

use App\Models\Driver;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpChannel;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 67 — the sign-in paths, measured against what an attacker with a phone list and
 * a loop would do.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);

        // Nothing leaves the test; the codes are read back from the row.
        $this->app->instance(OtpChannel::class, new class implements OtpChannel
        {
            public function send(string $phone, string $code): void {}
        });
    }

    public function test_password_guessing_on_one_account_is_throttled(): void
    {
        User::factory()->create([
            'phone' => '01090009599',
            'type' => 'driver',
            'password' => Hash::make('Correct#2026'),
            'is_active' => true,
        ]);

        // `POST /auth/login` had no throttle at all: the only thing between a password
        // list and the admin account was bcrypt's cost.
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(['X-App' => 'app'])
                ->postJson('/api/v1/auth/login', ['login' => '01090009599', 'password' => 'guess-'.$i])
                ->assertStatus(422)
                ->assertJsonPath('error', 'invalid_credentials');
        }

        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/auth/login', ['login' => '01090009599', 'password' => 'Correct#2026'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'login_rate_limited')
            ->assertHeader('Retry-After');

        // Another account from the same address is its own bucket: one place's typo
        // must not lock the dashboard's other places out (they share the BFF's IP).
        User::factory()->create([
            'phone' => '01090009598',
            'type' => 'driver',
            'password' => Hash::make('Other#2026x'),
            'is_active' => true,
        ]);

        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/auth/login', ['login' => '01090009598', 'password' => 'Other#2026x'])
            ->assertOk();
    }

    public function test_an_unknown_number_and_a_wrong_password_read_the_same(): void
    {
        User::factory()->create([
            'phone' => '01090009599',
            'type' => 'restaurant',
            'password' => Hash::make('Correct#2026'),
            'is_active' => true,
        ]);

        $unknown = $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/auth/login', ['login' => '01090009500', 'password' => 'whatever1'])
            ->assertStatus(422);
        $wrong = $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/auth/login', ['login' => '01090009599', 'password' => 'whatever1'])
            ->assertStatus(422);

        $this->assertSame($unknown->json(), $wrong->json());
    }

    public function test_forgot_password_does_not_tell_a_registered_number_from_a_stranger(): void
    {
        User::factory()->create(['phone' => '01090009599', 'type' => 'driver', 'is_active' => true]);

        $registered = [
            $this->postJson('/api/v1/auth/password/forgot', ['login' => '01090009599'])->status(),
            $this->postJson('/api/v1/auth/password/forgot', ['login' => '01090009599'])->json('error'),
        ];
        $stranger = [
            $this->postJson('/api/v1/auth/password/forgot', ['login' => '01090009500'])->status(),
            $this->postJson('/api/v1/auth/password/forgot', ['login' => '01090009500'])->json('error'),
        ];

        // Before: the registered number answered 429 `otp_resend_cooldown` on the second
        // ask and the stranger answered 200 for ever — a clean oracle for «is this phone
        // on the platform, and is it a captain, a place or the admin?».
        $this->assertSame($registered, $stranger);
        $this->assertSame([200, 'otp_resend_cooldown'], $registered);
        $this->assertSame(0, OtpCode::query()->where('phone', '01090009500')->count());
    }

    public function test_verification_codes_cannot_be_sprayed(): void
    {
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01090009599'])->assertOk();

        $last = null;

        for ($i = 0; $i < 11; $i++) {
            $last = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01090009599', 'code' => str_pad((string) $i, 6, '0')]);
        }

        // The per-code ceiling (`otp.max_attempts`) already existed; this is the ceiling
        // on the endpoint itself, per number and address, so a code that is re-sent
        // cannot be guessed at the speed of the network.
        $last->assertStatus(429)->assertJsonPath('error', 'otp_verify_rate_limited');
    }

    public function test_a_new_password_needs_letters_and_digits_on_every_path(): void
    {
        $user = User::factory()->create([
            'phone' => '01090009599',
            'type' => 'driver',
            'password' => Hash::make('Current#2026'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        // Registration already refused `12345678`; the change and the reset did not.
        $this->withHeaders(['X-App' => 'app'])->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Current#2026',
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])->assertStatus(422)->assertJsonValidationErrors('password', 'errors');

        $this->postJson('/api/v1/auth/password/reset', [
            'login' => '01090009599',
            'code' => '123456',
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ])->assertStatus(422)->assertJsonValidationErrors('password', 'errors');
    }

    public function test_a_temporary_password_opens_the_change_and_nothing_else(): void
    {
        $user = User::factory()->create([
            'phone' => '01090009599',
            'type' => 'driver',
            'password' => Hash::make('Temp#2026x'),
            'is_active' => true,
            'must_change_password' => true,
        ]);
        Driver::query()->create([
            'user_id' => $user->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $this->withHeaders(['X-App' => 'app']);

        // What the captain shell reads before it shows the forced change.
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/driver/me')->assertOk();
        $this->getJson('/api/v1/driver/stats')->assertOk();

        // Work is refused until the password is the captain's own.
        $this->postJson('/api/v1/driver/status', ['is_online' => true])
            ->assertStatus(403)->assertJsonPath('error', 'must_change_password');
        $this->getJson('/api/v1/driver/offers')
            ->assertStatus(403)->assertJsonPath('error', 'must_change_password');

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Temp#2026x',
            'password' => 'Mine#2026x',
            'password_confirmation' => 'Mine#2026x',
        ])->assertOk();

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/v1/driver/offers')->assertOk();
    }

    public function test_the_wall_is_for_staff_accounts_only(): void
    {
        $customer = User::factory()->create([
            'type' => 'customer',
            'is_active' => true,
            'must_change_password' => true,
        ]);

        Sanctum::actingAs($customer);

        $this->withHeaders(['X-App' => 'app'])->getJson('/api/v1/cart')->assertOk();
    }

    public function test_an_api_route_answers_json_whatever_the_accept_header_says(): void
    {
        // Production answered this with a 500 HTML «Server Error» page.
        $response = $this->call('GET', '/api/v1/admin/users', server: ['HTTP_ACCEPT' => 'text/html']);

        $response->assertStatus(401);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('unauthenticated', $response->json('error'));

        $wrongVerb = $this->call('GET', '/api/v1/auth/login', server: ['HTTP_ACCEPT' => '*/*']);
        $wrongVerb->assertStatus(405);
        $this->assertSame('method_not_allowed', $wrongVerb->json('error'));
        $this->assertFalse($wrongVerb->json('success'));
    }
}
