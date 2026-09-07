<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «إنشاء حساب» — and the two things it is not allowed to do.
 *
 * Operator, 2026-09-07: «مش عايزه يختار هو customer ولا driver… هو يخش يكتب الرقم
 * والباسورد، فهيتعرف من الباك إن ده driver، فيدخله على وجهة الـdriver على طول». One login
 * screen, one phone, one role — «مفيش نفس الرقم يبقى driver وcustomer».
 */
class CustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The staging code, so the test types what a tester types.
        config(['otp.static' => true, 'otp.static_code' => '123456']);
        $this->withHeader('X-App', 'app');
    }

    private function verifyForRegistration(string $phone = '01011122233'): string
    {
        $this->postJson('/api/v1/auth/otp/send', ['phone' => $phone, 'purpose' => 'register'])->assertOk();

        $verified = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => '123456',
            'purpose' => 'register',
        ]);

        $verified->assertOk();

        // No account, no session — just proof the phone answered.
        $this->assertNull($verified->json('data.token'));
        $this->assertNull(User::query()->where('phone', $phone)->first());

        return $verified->json('data.registration_token');
    }

    public function test_a_new_customer_verifies_a_code_and_then_chooses_a_password(): void
    {
        $token = $this->verifyForRegistration();

        $created = $this->postJson('/api/v1/auth/register', [
            'registration_token' => $token,
            'name' => 'أحمد الشناوي',
            'password' => 'Ahmed@123',
        ]);

        $created->assertCreated();
        $this->assertNotEmpty($created->json('data.token'));
        $this->assertSame('customer', $created->json('data.user.type'));

        $user = User::query()->where('phone', '01011122233')->firstOrFail();
        $this->assertSame(UserType::Customer, $user->type);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertFalse($user->must_change_password);

        // …and that password is the one that signs them in from now on.
        $this->postJson('/api/v1/auth/login', [
            'login' => '01011122233',
            'password' => 'Ahmed@123',
        ])->assertOk()->assertJsonPath('data.user.type', 'customer');
    }

    public function test_a_registration_token_is_spent_once(): void
    {
        $token = $this->verifyForRegistration();

        $this->postJson('/api/v1/auth/register', [
            'registration_token' => $token,
            'name' => 'أحمد',
            'password' => 'Ahmed@123',
        ])->assertCreated();

        // The double tap on a slow button. Without the token being destroyed inside the
        // same transaction that creates the user, this is a 500 on `users.phone`.
        $this->postJson('/api/v1/auth/register', [
            'registration_token' => $token,
            'name' => 'أحمد',
            'password' => 'Ahmed@123',
        ])->assertStatus(422)->assertJsonPath('error', 'registration_expired');

        $this->assertSame(1, User::query()->where('phone', '01011122233')->count());
    }

    public function test_a_phone_that_is_already_a_captain_cannot_register_as_a_customer(): void
    {
        $captain = User::factory()->create(['phone' => '01011122233', 'type' => 'driver']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'register'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '01011122233',
            'code' => '123456',
            'purpose' => 'register',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'phone_taken')
            ->assertJsonPath('meta.account_type', 'driver');

        $this->assertSame(1, User::query()->where('phone', $captain->phone)->count());
    }

    public function test_the_otp_login_path_still_names_the_account_type_it_refuses(): void
    {
        User::factory()->create(['phone' => '01011122233', 'type' => 'driver']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'login'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '123456', 'purpose' => 'login'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'wrong_client')
            ->assertJsonPath('meta.account_type', 'driver');
    }

    /**
     * One login screen, two shells.
     *
     * `X-App: app` used to mean «driver», so a customer's own password was answered
     * `wrong_client` by the only login form the app has.
     */
    public function test_one_login_endpoint_answers_for_both_shells(): void
    {
        $this->seedLocation();

        $customer = User::factory()->create([
            'phone' => '01011122233', 'type' => 'customer',
            'password' => bcrypt('Ahmed@123'),
        ]);
        $captainUser = User::factory()->create([
            'phone' => '01011122244', 'type' => 'driver',
            'password' => bcrypt('Ahmed@123'),
        ]);
        Driver::query()->create([
            'user_id' => $captainUser->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', ['login' => $customer->phone, 'password' => 'Ahmed@123'])
            ->assertOk()->assertJsonPath('data.user.type', 'customer');

        $this->postJson('/api/v1/auth/login', ['login' => $captainUser->phone, 'password' => 'Ahmed@123'])
            ->assertOk()->assertJsonPath('data.user.type', 'driver');

        // The dashboards are unchanged: a captain's password on `admin.` is still refused.
        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => $captainUser->phone, 'password' => 'Ahmed@123'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'wrong_client');
    }

    /**
     * `must_change_password` was a formality.
     *
     * An admin-created captain could satisfy the forced change by retyping the password
     * the admin had just read to them over WhatsApp, and the credential stayed live.
     */
    public function test_the_new_password_may_not_be_the_old_one(): void
    {
        $user = User::factory()->create([
            'type' => 'driver',
            'password' => bcrypt('Ahmed@123'),
            'must_change_password' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Ahmed@123',
            'password' => 'Ahmed@123',
            'password_confirmation' => 'Ahmed@123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'password_unchanged');

        $this->assertTrue($user->fresh()->must_change_password);

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Ahmed@123',
            'password' => 'Ahmed@1234',
            'password_confirmation' => 'Ahmed@1234',
        ])->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    /**
     * The accounts that existed before passwords did.
     *
     * `ResetPasswordAction` refused any account with no password — which was every
     * customer on the platform, i.e. exactly the people who needed to set one.
     */
    public function test_a_legacy_otp_only_customer_can_set_a_first_password(): void
    {
        $user = User::factory()->create(['phone' => '01011122233', 'type' => 'customer', 'password' => null]);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => $user->phone])->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [
            'login' => $user->phone,
            'code' => '123456',
            'password' => 'Ahmed@123',
            'password_confirmation' => 'Ahmed@123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['login' => $user->phone, 'password' => 'Ahmed@123'])
            ->assertOk()->assertJsonPath('data.user.type', 'customer');
    }
}
